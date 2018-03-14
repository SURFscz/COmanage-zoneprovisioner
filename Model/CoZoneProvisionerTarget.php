<?php
/**
 * COmanage Registry CO LDAP Fixed Provisioner Target Model
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugin
 * @since         COmanage Registry vTODO
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("CoProvisionerPluginTarget", "Model");
App::uses("ZonePerson", "Model");
App::uses("ZoneService", "Model");
App::uses("COGroup", "Model");

class CoZoneProvisionerTarget extends CoProvisionerPluginTarget {
  // Define class name for cake
  public $name = "CoZoneProvisionerTarget";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array("CoProvisioningTarget");

  public $hasMany = array();

  // Default display field for cake generated views
  public $displayField = "serverurl";

  // Validation rules for table elements
  public $validate = array(
    'co_provisioning_target_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'message' => 'A CO Provisioning Target ID must be provided'
    )
  );

  // Cache of schema plugins, populated by supportedAttributes
  protected $plugins = array();


  /**
   * Assemble services
   *
   * @param  Array                  $provisioningData         CO Person or CO Group Data used for provisioning
   * @return Array List of service entitlement_uri's
   * @throws UnderflowException
   */
  protected function assembleServices($provisioningData) {
    $coid = intval($provisioningData["Co"]["id"]);
    $uris=array();
    if(!empty($provisioningData['CoGroupMember'])) {
      foreach($provisioningData['CoGroupMember'] as $gm) {
        if(  isset($gm['member']) && $gm['member']
          && !empty($gm['CoGroup']['name'])) {
          $this->CoProvisioningTarget->Co->CoGroup->contain('CoService');
          $grp=$this->CoProvisioningTarget->Co->CoGroup->find('first',array('conditions'=>array('id'=>$gm['CoGroup']['id'])));
          foreach($grp['CoService'] as $service) {
            $uris[$service['entitlement_uri']]=$service;
          }
        }
      }
    }

    // check these services exists as ZoneService
    $this->ZoneService->contain(FALSE);
    $services=$this->ZoneService->find('all',array('conditions'=> array('metadata' => array_keys($uris), 'co_id' => $coid)));
    $metadata=array();
    foreach($services as $service) {
      $metadata[$service['ZoneService']['metadata']] = $service['ZoneService']['id'];
    }
    foreach($uris as $uri=>$model) {
      if(!isset($metadata[$uri]) && strlen(trim($uri))) {
        // create a new ZoneService
        $this->ZoneService->save(array(
          'metadata'=>$uri,
          'co_id' => $coid,
          'attributes' => json_encode(array('co_service_id'=>$model['id']))
          ));
        $service = $this->ZoneService->find('first',array('conditions'=>array('id'=>$this->ZoneService->id)));
        $metadata[$uri] = $service['ZoneService']['id'];
      }
    }
    return $metadata;
  }

  /**
   * Assemble attributes
   *
   * @since  COmanage Registry v0.8
   * @param  Array                  $provisioningData         CO Person or CO Group Data used for provisioning
   * @return Array Attribute data suitable for creating a ZonePerson
   * @throws UnderflowException
   */
  protected function assembleAttributes($provisioningData) {
    $configured_attributes=Configure::read('scz.attributes');

    // load external ldapschema plugins
    $this->plugins = $this->loadAvailablePlugins('ldapschema');

    // First see if we're working with a Group record or a Person record
    $person = isset($provisioningData['CoPerson']['id']);
    $group = isset($provisioningData['CoGroup']['id']);
    $scope_suffix = $this->templateReplace(Configure::read('scz.scope_suffix'),$provisioningData);

    // Marshalled attributes ready for export
    $attributes = array();

    // loop over all configured attributes. All configured attributes are required
    foreach($configured_attributes as $export_name => $constitution) {
      $attr = isset($constitution['attribute']) ? $constitution['attribute'] : $export_name;

      if(isset($constitution['plugin'])) {
        // plugin to ldapschema to allow different plugins to create attributes for us
        $pmodel = $this->plugins[ $constitution['plugin'] ];
        $pattrs = $pmodel->assemblePluginAttributes(
            array('attributes' => array(
              $attr => array(
                'required'   => true,
                'multiple'   => true
              )),
            $provisioningData));

        if(isset($pattrs[$attr])) {
          // Merge into the marshalled attributes.
          $attributes[$export_name] = $pattrs[$attr];
        }
        // Continue the loop (skip the standard processing)
        continue;
      }

      $targetType = isset($constitution['type']) ? $constitution['type'] : '';
      // put single values in an array for convenience
      if(!empty($targetType) && !is_array($targetType)) $targetType=array($targetType);
      switch($attr) {
        // Name attributes
        case 'cn':
          // Currently only preferred name supported (CO-333)
          $attributes[$export_name] = generateCn($provisioningData['PrimaryName']);
          break;
        case 'givenName':
          // Currently only preferred name supported (CO-333)
          $attributes[$export_name] = $provisioningData['PrimaryName']['given'];
          break;
        case 'sn':
          // Currently only preferred name supported (CO-333)
          if(!empty($provisioningData['PrimaryName']['family'])) {
             $attributes[$export_name] = $provisioningData['PrimaryName']['family'];
          }
          break;
        case 'displayName':
        case 'eduPersonNickname':
          // Walk through each name
          foreach($provisioningData['Name'] as $n) {
            if(empty($targetType) || in_array($n['type'], $targetType)) {
              $attributes[$export_name][] = generateCn($n);
            }
          }
          break;

        // Attributes from CO Person Role
        case 'eduPersonAffiliation':
        case 'eduPersonScopedAffiliation':
        case 'employeeType':
        case 'o':
        case 'ou':
        case 'title':
          // Map the attribute to the column
          $cols = array(
            'eduPersonAffiliation' => 'affiliation',
            'eduPersonScopedAffiliation' => 'affiliation',
            'employeeType' => 'affiliation',
            'o' => 'o',
            'ou' => 'ou',
            'title' => 'title'
          );

          // Walk through each role
          foreach($provisioningData['CoPersonRole'] as $r) {
            if(!empty($r[ $cols[$attr] ])) {
              if(  $attr == 'eduPersonAffiliation'
                || $attr == 'eduPersonScopedAffiliation') {
                $affilmap = $this->CoProvisioningTarget->Co->CoExtendedType->affiliationMap($provisioningData['Co']['id']);

                if(!empty($affilmap[ $r[ $cols[$attr] ]])) {
                  // Append scope, if so configured
                  $scope = '';

                  if($attr == 'eduPersonScopedAffiliation') {
                    if(!empty($scope_suffix)) {
                      $scope = '@' . $scope_suffix;
                    } else {
                      // Don't add this attribute since we don't have a scope
                      continue;
                    }
                  }

                  $attributes[$export_name][] = $affilmap[ $r[ $cols[$attr] ] ] . $scope;
                }
              } else {
                $attributes[$export_name][] = $r[ $cols[$attr] ];
              }
            }
          }
          break;

        // Attributes from models attached to CO Person
        case 'eduPersonOrcid':
        case 'eduPersonPrincipalName':
        case 'eduPersonPrincipalNamePrior':
        case 'eduPersonUniqueId':
        case 'employeeNumber':
        case 'uid':
        case 'mail':
          // Map the attribute to the model and column
          $mods = array(
            'eduPersonOrcid' => 'Identifier',
            'eduPersonPrincipalName' => 'Identifier',
            'eduPersonPrincipalNamePrior' => 'Identifier',
            'eduPersonUniqueId' => 'Identifier',
            'employeeNumber' => 'Identifier',
            'mail' => 'EmailAddress',
            'uid' => 'Identifier'
          );

          $cols = array(
            'eduPersonOrcid' => 'identifier',
            'eduPersonPrincipalName' => 'identifier',
            'eduPersonPrincipalNamePrior' => 'identifier',
            'eduPersonUniqueId' => 'identifier',
            'employeeNumber' => 'identifier',
            'mail' => 'mail',
            'uid' => 'identifier'
          );

          if($attr == 'eduPersonOrcid') {
            // Force target type to Orcid. Note we don't validate that the value is in
            // URL format (http://orcid.org/0000-0001-2345-6789) but perhaps we should.
            $targetType = array(IdentifierEnum::ORCID);
          }

          $scope = '';
          if($attr == 'eduPersonUniqueId') {
            // Append scope if set, skip otherwise
            if(!empty($scope_suffix)) {
              $scope = '@' . $scope_suffix;
            } else {
              // Don't add this attribute since we don't have a scope
              continue;
            }
          }

          $modelList = null;
          if(isset($constitution['use_org']) && $constitution['use_org']) {
            // Use organizational identity value for this attribute

            // If there is more than one CoOrgIdentityLink, push them all onto the list
            // The structure is something like
            // $provisioningData['CoOrgIdentityLink'][0]['OrgIdentity']['Identifier'][0][identifier]
            if(isset($provisioningData['CoOrgIdentityLink'])) {
              foreach($provisioningData['CoOrgIdentityLink'] as $lnk) {
                if(isset($lnk['OrgIdentity'][ $mods[$attr] ])) {
                  foreach($lnk['OrgIdentity'][ $mods[$attr] ] as $x) {
                    $modelList[] = $x;
                  }
                }
              }
            }
          } elseif(isset($provisioningData[ $mods[$attr] ])) {
            // Use CO Person value for this attribute
            $modelList = $provisioningData[ $mods[$attr] ];
          }

          if(isset($modelList)) {
            foreach($modelList as $m) {
              // If a type is set, make sure it matches
              if(empty($targetType) || in_array($m['type'], $targetType)) {
                // And finally that the attribute itself is set
                if(!empty($m[ $cols[$attr] ])) {
                  $attributes[$export_name][] = $m[ $cols[$attr] ] . $scope;
                }
              }
            }
          }
          break;

        case 'sshPublicKey':
          foreach($provisioningData['SshKey'] as $sk) {
            global $ssh_ti;
            $attributes[$export_name][] = $ssh_ti[ $sk['type'] ] . " " . $sk['skey'] . " " . $sk['comment'];
          }
          break;

        // Attributes from models attached to CO Person Role
        case 'facsimileTelephoneNumber':
        case 'l':
        case 'mobile':
        case 'postalCode':
        case 'roomNumber':
        case 'st':
        case 'street':
        case 'telephoneNumber':
          // Map the attribute to the model and column
          $mods = array(
            'facsimileTelephoneNumber' => 'TelephoneNumber',
            'l' => 'Address',
            'mobile' => 'TelephoneNumber',
            'postalCode' => 'Address',
            'roomNumber' => 'Address',
            'st' => 'Address',
            'street' => 'Address',
            'telephoneNumber' => 'TelephoneNumber'
          );

          $cols = array(
            'facsimileTelephoneNumber' => 'number',
            'l' => 'locality',
            'mobile' => 'number',
            'postalCode' => 'postal_code',
            'roomNumber' => 'room',
            'st' => 'state',
            'street' => 'street',
            'telephoneNumber' => 'number'
          );

          // Walk through each role, each of which can have more than one
          foreach($provisioningData['CoPersonRole'] as $r) {
            if(isset($r[ $mods[$attr] ])) {
              foreach($r[ $mods[$attr] ] as $m) {
                // If a type is set, make sure it matches
                if(empty($targetType) || in_array($m['type'], $targetType)) {
                  // And finally that the attribute itself is set
                  if(!empty($m[ $cols[$attr] ])) {
                    if($mods[$attr] == 'TelephoneNumber') {
                      // Handle these specially... we want to format the number
                      // from the various components of the record
                      $attributes[$export_name][] = formatTelephone($m);
                    } else {
                      $attributes[$export_name][] = $m[ $cols[$attr] ];
                    }
                  }
                }
              }
            }
          }
          break;

        // Group attributes (cn is covered above)
        case 'description':
          // A blank description is invalid, so don't populate if empty
          if(!empty($provisioningData['CoGroup']['description'])) {
            $attributes[$export_name] = $provisioningData['CoGroup']['description'];
          }
          break;

        case 'isMemberOf':
          if(!empty($provisioningData['CoGroupMember'])) {
            foreach($provisioningData['CoGroupMember'] as $gm) {
              if(  isset($gm['member']) && $gm['member']
                && !empty($gm['CoGroup']['name'])) {
                $attributes[$export_name][] = $provisioningData['Co']['name'] .':'.$gm['CoGroup']['name'];
              }
            }
          }
          break;

        // eduPersonEntitlement is based on Group memberships
        case 'eduPersonEntitlement':
          if(!empty($provisioningData['CoGroupMember'])) {
            $entGroupIds = Hash::extract($provisioningData['CoGroupMember'], '{n}.co_group_id');
            $attributes[$export_name] = $this->CoProvisioningTarget
                                             ->Co
                                             ->CoGroup
                                             ->CoService
                                             ->mapCoGroupsToEntitlements($provisioningData['Co']['id'], $entGroupIds);
          }
          break;

        // posixAccount attributes
        case 'gecos':
          // Construct using same name as cn
          $attributes[$export_name] = generateCn($provisioningData['PrimaryName']) . ",,,";
          break;
        case 'gidNumber':
        case 'homeDirectory':
        case 'uidNumber':
          // We pull these attributes from Identifiers with types of the same name
          // as an experimental implementation for CO-863.
          foreach($provisioningData['Identifier'] as $m) {
            if(isset($m['type'])
              && $m['type'] == $attr
              && $m['status'] == StatusEnum::Active) {
              $attributes[$export_name] = $m['identifier'];
              break;
            }
          }
          break;
      }

    } // foreach


    // Check for multi-valued attributes that we need to export single value.
    // There are 2 situations:
    // - the attribute can only ever have 1 value
    // - the attribute can have multiple values, but they must be unique
    // - the attribute does not care
    //
    // Because the latter does not differ significantly from the second, we
    // implement 'unique' by default, unless multiple is set to 'allow'
    $multiple = isset($constitution['multiple']) && in_array($constitution['multiple'],array('single','unique','allow')) ? $constitution['multiple'] : 'unique';

    foreach(array_keys($attributes) as $a) {
      if(is_array($attributes[$a])) {
        if($multiple == 'unique') {
          $case_sensitive = isset($constitution['case']) ? $constitution['case'] : FALSE;
          $duplicates = array();

          foreach($attributes[$a] as $v) {
            // Clean up the attribute before checking
            $v = $this->cleanAttribute($v);
            $test_value = $case_sensitive ? strtolower($v) : $v;
            $duplicates[$test_value]=$v;
          }
          $attributes[$a] = array_values($duplicates);
        } else if($multiple == 'allow') {
          $newa=array();
          foreach($attributes[$a] as $v) {
            // Clean up the attribute before checking
            $newa[] = $this->cleanAttribute($v);
          }
          $attributes[$a] = $newa;
        } else if($multiple == 'single') {
          // take the first entry
          if(sizeof($attributes[$a]) > 0) {
            $attributes[$a] = $this->cleanAttribute($attributes[$a][0]);
          }
        }
      } else {
        $attributes[$a] = $this->cleanAttribute($attributes[$a]);
      }
    }
    return $attributes;
  }

  /**
   * Template Replace the attribute value
   *
   * @param  String Attribute content
   * @param  Array provisioningData
   * @return String Modified attribute
   */
  private function templateReplace($attribute, $provisioningData) {
    return str_replace(array("{CO}"),array($provisioningData['Co']['name']), $attribute);
  }


  /**
   * Normalize the attribute value for our purposes
   *
   * @param  String Attribute content
   * @return String Modified attribute
   */
  private function cleanAttribute($attribute) {
    // TODO: determine what values are allowed and which are not
    return $attribute;
  }

  /**
   * Convert the list of attributes to something we can use in the ZonePerson attribute field
   *
   * @param  Array Attribute list
   * @return String Attribute field content
   */
  private function convertAttributes($attributes) {
    $keepuid = Configure::read('scz.export_uid_attribute');
    if(!$keepuid) {
      $uidattr = Configure::read('scz.uid');
      if(isset($attributes[$uidattr])) {
        unset($attributes[$uidattr]);
      }
    }
    return json_encode($attributes);
  }

  /**
   * Retrieve and normalize the UID attribute used as main ZonePerson identifier
   *
   * @param  Array Attributes
   * @return String UID attribute value
   */
  private function getUID($attributes) {
    $uidattr = Configure::read('scz.uid');
    if(!isset($attributes[$uidattr])) {
      //throw new RuntimeException("Attribute missing");
      return null;
    }

    $uidattr = $attributes[$uidattr];
    if(is_array($uidattr)) $uidattr=$uidattr[0];
    return (string)$uidattr;
  }

  /**
   * Provision for the specified CO Person.
   *
   * @since  COmanage Registry vTODO
   * @param  Array CO Provisioning Target data
   * @param  ProvisioningActionEnum Registry transaction type triggering provisioning
   * @param  Array Provisioning data, populated with ['CoPerson'] or ['CoGroup']
   * @return Boolean True on success
   * @throws InvalidArgumentException If $coPersonId not found
   * @throws RuntimeException For other errors
   */

  public function provision($coProvisioningTargetData, $op, $provisioningData) {
    // First figure out what to do
    $delete   = false;
    $add      = false;
    $modify   = false;

    if(empty($provisioningData['CoPerson']['id'])) {
      // do not provision group information
      return TRUE;
    }

    switch($op) {
      case ProvisioningActionEnum::CoPersonAdded:
        $add = true;
        break;
      case ProvisioningActionEnum::CoPersonDeleted:
        $delete = true;
        break;
      case ProvisioningActionEnum::CoPersonPetitionProvisioned:
      case ProvisioningActionEnum::CoPersonPipelineProvisioned:
      case ProvisioningActionEnum::CoPersonReprovisionRequested:
      case ProvisioningActionEnum::CoPersonUnexpired:
        $modify = true;
        break;
      case ProvisioningActionEnum::CoPersonExpired:
      case ProvisioningActionEnum::CoPersonEnteredGracePeriod:
      case ProvisioningActionEnum::CoPersonUnexpired:
      case ProvisioningActionEnum::CoPersonUpdated:
        if(!in_array($provisioningData['CoPerson']['status'],
                     array(StatusEnum::Active,
                           StatusEnum::Expired,
                           StatusEnum::GracePeriod,
                           StatusEnum::Suspended))) {
          // Convert this to a delete operation.
          $delete = true;
        } else {
          // An update may cause an existing person to be written for the first time
          // or for an unexpectedly removed entry to be replaced
          $modify = true;
        }
        break;
      case ProvisioningActionEnum::CoGroupAdded:
      case ProvisioningActionEnum::CoGroupDeleted:
      case ProvisioningActionEnum::CoGroupUpdated:
      case ProvisioningActionEnum::CoGroupReprovisionRequested:
        // group provisioning not required
        return TRUE;
        break;
      default:
        throw new RuntimeException("Not Implemented");
        break;
    }

    $config = Configure::load('scz','default');
    $this->ZonePerson = ClassRegistry::init('ZoneProvisioner.ZonePerson');
    $this->ZoneService = ClassRegistry::init('ZoneProvisioner.ZoneService');

    $attributes = $this->assembleAttributes($provisioningData);
    try {
      $services = $this->assembleServices($provisioningData);
    } catch(Exception $e) {
      $services=array();
    }
    $uidattr = $this->getUID($attributes);
    if($uidattr === null)
    {
        // this person cannot be provisioned, because it is not complete
        $uidattr = Configure::read('scz.uid');
        CakeLog::write('error','zoneprovisioner: skipping COPerson "'.$provisioningData['CoPerson']['id'].'" because UID '.$uidattr.' is not present in the list of attributes');
        return true;
    }
    $coid = intval($provisioningData["Co"]["id"]);
    $person = $this->ZonePerson->find('first',array('conditions'=>array('uid'=>$uidattr, "co_id" => $coid)));
    if(empty($person)) {
      if($delete) {
        // we are already done
        return TRUE;
      }
      $add=true;
      try {
        $this->ZonePerson->save(array('uid'=>$uidattr, "co_id" => $coid, 'attributes'=>''));
        $person = $this->ZonePerson->find('first',array('conditions'=>array('id'=>$this->ZonePerson->id)));
      } catch(Exception $e) {
        $person=null;
      }
    }

    if(empty($person)) {
      CakeLog::write('error','zoneprovisioner: cannot connect to remote database');
      throw new RuntimeException("Error writing to database");
    }

    if($delete) {
      // to maintain database integrity, we deleteAll based on the uid, to avoid situations where we might
      // have duplicates. This would only occur if we have 2 simultaneous provisioning operations, where
      // one is adding and the other is deleting the ZonePerson. By removing all ZonePersons here, there
      // is only a problem when a user is deprovisioned and provisioned at the same time, which should never
      // occur, because deprovisioning has to finish before provisioning can take place.
      // Theoretically, if we deprovision a user and at the same time a slower process is changing something
      // to that user, the delete operation could have been performed before the change was done. The change
      // would then add the new ZonePerson. For that reason, we add the user at the start of the provisioning
      // process, to minimise the chances a slow-change will be outpaced by a fast-delete.
      // However, the assembleAttributes call is probably by far the slowest part anyway.
      $this->ZonePerson->deleteAll(array('uid'=>$uidattr, "co_id" => $coid),true);
    } else if($add || $modify) {
      $person['ZonePerson']['attributes']=$this->convertAttributes($attributes);
      $person['ZoneService']=array_values($services);
      $this->ZonePerson->saveAssociated($person,array('validate'=>FALSE));
    }

    return true;
  }

  /**
   * Determine the provisioning status of this target for a CO Person ID.
   *
   * @param  Integer CO Provisioning Target ID
   * @param  Integer CO Person ID (null if CO Group ID is specified)
   * @param  Integer CO Group ID (null if CO Person ID is specified)
   * @return Array ProvisioningStatusEnum, Timestamp of last update in epoch seconds, Comment
   * @throws RuntimeException When uidattr is not set
   */

  public function status($coProvisioningTargetId, $coPersonId, $coGroupId=null) {
    $ret = array(
      'status'    => ProvisioningStatusEnum::Unknown,
      'timestamp' => null,
      'comment'   => ""
    );

    $config = Configure::load('scz','default');
    $attributes = $this->assembleAttributes($provisioningData);
    $uidattr = $this->getUID($attributes);
    $person = $this->ZonePerson->find('first',array('conditions'=>array('uid'=>$uidattr, "co_id" => $this->CoProvisioningData->Co->id)));

    if(!empty($person)) {
      $ret['timestamp'] = $person['modified'];
      $ret['status'] = ProvisioningStatusEnum::Provisioned;
      $ret['comment'] = $uidattr;
    } else {
      $ret['status'] = ProvisioningStatusEnum::NotProvisioned;
      $ret['comment'] = $uidattr;
    }
    return $ret;
  }
}
