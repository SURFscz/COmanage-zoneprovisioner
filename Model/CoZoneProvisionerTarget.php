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
  public $actsAs = array('Containable','ZoneProvisioner.Role');

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

  public function findServicesByPerson($coId, $coPersonId=null, $couId=null) {
    $visibility = array(VisibilityEnum::Unauthenticated);
    $groups = null;

    if($coPersonId) {
      // Is this person an admin?
      if($this->isCoAdmin($coPersonId, $coId)) {
        $visibility[] = VisibilityEnum::CoAdmin;
      }

      if($this->isCoPerson($coPersonId, $coId)) {
        $visibility[] = VisibilityEnum::CoMember;

        // The join on CoGroupMember would be way too complicated, it'd be easier
        // to just pull two queries and merge. Instead, we'll just pull everything
        // flagged for CoGroupMember and then filter the results manually based on
        // the person's groups.
        $visibility[] = VisibilityEnum::CoGroupMember;

        $groups = $this->CoProvisioningTarget->Co->CoGroup->findForCoPerson($coPersonId, null, null, null, false);
      }
    }
    $args = array();
    $args['conditions']['CoService.co_id'] = $coId;
    if($couId !== false) {
      // COU ID does not constrain visibility, it's basically like having a COU level portal
      $args['conditions']['CoService.cou_id'] = $couId;
    }
    $args['conditions']['CoService.visibility'] = $visibility;
    $args['conditions']['CoService.status'] = SuspendableStatusEnum::Active;
    $args['order'] = 'CoService.name';
    $args['contain'] = false;
    $services = $this->CoProvisioningTarget->Co->CoService->find('all', $args);
    $groupIds = array();
    if(!empty($groups) && !empty($services) && $coPersonId) {
      // If $coPersonId is not set, there won't be any services with a CoGroupMember visibility

      $groupIds = Hash::extract($groups, '{n}.CoGroup.id');
    }

    // Walk the list of services and remove any with a group_id that doesn't match

    for($i = count($services) - 1;$i >= 0;$i--) {
      if($services[$i]['CoService']['visibility'] == VisibilityEnum::CoGroupMember
         && $services[$i]['CoService']['co_group_id']
         && !in_array($services[$i]['CoService']['co_group_id'], $groupIds)) {
        unset($services[$i]);
      }
    }

    return $services;
  }

  /**
   * Get Service metadata
   * Services contain 3 fields for URLs: service_url, service_label and entitlement_uri
   * The service_label is the field we want, but we used entitlement_uri in the past and
   * service_url was introduced before service_label
   *
   * @param  Array service Service object
   * @return String service metadata url
   */
  private function serviceMetadata($service) {
    if(!empty($service['service_label']) && strlen(trim($service['service_label'])) > 0) {
      return $service['service_label'];
    }
    if(!empty($service['entitlement_uri']) && strlen(trim($service['entitlement_uri'])) > 0) {
      return $service['entitlement_uri'];
    }
    if(!empty($service['service_url']) && strlen(trim($service['service_url'])) > 0) {
      return $service['service_url'];
    }
    return '';
  }

  /**
   * Assemble services
   *
   * @param  Array                  $provisioningData         CO Person or CO Group Data used for provisioning
   * @return Array List of service entitlement_uri's
   * @throws UnderflowException
   */
  protected function assembleServices($provisioningData) {

    $coid=$provisioningData["Co"]["id"];
    $services = $this->findServicesByPerson(
      $coid,
      $provisioningData["CoPerson"]["id"],
      false
    );

    $uris=array();
    foreach($services as $service) {
      $metadata_url = $this->serviceMetadata($service['CoService']);
      if(!empty($metadata_url)) {
        $uris[$metadata_url]=$service['CoService'];
      }
    }

    // check these services exists as ZoneService
    $this->ZoneService->contain(FALSE);
    $services=$this->ZoneService->find('all',array('conditions'=> array('metadata' => array_keys($uris), 'co_id' => $coid)));
    $metadata=array();
    foreach($services as $service) {
      $metadata_url = $service['ZoneService']['metadata'];
      if(!empty($metadata_url)) {
        $metadata[$metadata_url]=$service['ZoneService']['id'];
      }
    }
    foreach($uris as $uri=>$model) {
      if(!isset($metadata[$uri])) {

        // create a new ZoneService
        $this->ZoneService->clear();
        $this->ZoneService->save(array(
          'metadata'=>$uri,
          'co_id' => $coid,
          'attributes' => json_encode(array(
            'co_service_id'=>$model['id'],
            'service_url' => $model['service_url'],
            'entitlement_uri' => $model['entitlement_uri']
            ))
          ));
        $metadata[$uri] = $this->ZoneService->id;
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
    $constitutions = array(); // not exported metadata

    // loop over all configured attributes. All configured attributes are required
    foreach($configured_attributes as $export_name => $constitution) {
      $attr = isset($constitution['attribute']) ? $constitution['attribute'] : $export_name;
      $constitutions[$export_name] = $constitution; // store meta-data for later use

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
          // Currently only preferred name supported
          $attributes[$export_name] = generateCn($provisioningData['PrimaryName']);
          break;
        case 'givenName':
          // Currently only preferred name supported
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
            $attributes[$export_name] = $attributes[$export_name] + $this->mapGroupsToEntitlement($provisioningData['Co'],$provisioningData['CoGroupMember']);
          }
          break;

        // posixAccount attributes
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

    foreach(array_keys($attributes) as $a) {
      $constitution = isset($constitutions[$a]) ? $constitutions[$a] : array();

      // Because the does-not-care does not differ significantly from the unique,
      // (implementation-wise) we implement 'unique' by default, unless multiple
      // is set to 'allow'
      $multiple = isset($constitution['multiple']) && in_array($constitution['multiple'],array('single','unique','allow'))
            ? $constitution['multiple'] : 'unique';

      if(is_array($attributes[$a])) {
        // remove empty arrays, they are senseless
        if(sizeof($attributes[$a]) == 0) {
          unset($attributes[$a]);
        } else if($multiple == 'unique') {
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
   * The ZoneProvisioner is simple: either add or delete (or: to-delete-or-not-to-delete)
   * If not deleting, we do a saveAssociated, which will insert-or-update (add/modify)
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

    if(empty($provisioningData['CoPerson']['id'])) {
      // do not provision group information
      return TRUE;
    }

    switch($op) {
      case ProvisioningActionEnum::CoPersonAdded:
      case ProvisioningActionEnum::CoPersonUnexpired:
      case ProvisioningActionEnum::CoPersonPetitionProvisioned:
      case ProvisioningActionEnum::CoPersonPipelineProvisioned:
      case ProvisioningActionEnum::CoPersonReprovisionRequested:
      case ProvisioningActionEnum::CoPersonUpdated:
        if($provisioningData['CoPerson']['status'] == StatusEnum::Active) {
          $delete = false;
        } else {
          $delete=true;
        }
        break;
      case ProvisioningActionEnum::CoPersonDeleted:
      case ProvisioningActionEnum::CoPersonExpired:
      case ProvisioningActionEnum::CoPersonEnteredGracePeriod:
        $delete = true;
        break;
      case ProvisioningActionEnum::CoGroupAdded:
      case ProvisioningActionEnum::CoGroupDeleted:
      case ProvisioningActionEnum::CoGroupUpdated:
      case ProvisioningActionEnum::CoGroupReprovisionRequested:
      case ProvisioningActionEnum::AuthenticatorUpdated:
      case ProvisioningActionEnum::CoEmailListAdded:
      case ProvisioningActionEnum::CoEmailListDeleted:
      case ProvisioningActionEnum::CoEmailListReprovisionRequested:
      case ProvisioningActionEnum::CoEmailListUpdated:
      default:
        throw new RuntimeException("Not Implemented");
        break;
    }
    $actionid=uniqid();
    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "operation" => $op,
                        "message" => ($delete ? "Removing user ":"Adding user ").generateCn($provisioningData['PrimaryName']),
                        "co_id"=>$provisioningData["Co"]["id"],
                        "co_name"=>$provisioningData["Co"]["name"]));

    $config = Configure::load('scz','default');
    $this->ZonePerson = ClassRegistry::init('ZoneProvisioner.ZonePerson');
    $this->ZoneService = ClassRegistry::init('ZoneProvisioner.ZoneService');

    $attributes = $this->assembleAttributes($provisioningData);

    try {

      $services = $this->assembleServices($provisioningData);
      if(empty($services)) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "no services configured"));
      }
    } catch(Exception $e) {
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "message" => "failed to assemble services: ".$e->getMessage()));
      $services=array();
    }

    $uidattr = $this->getUID($attributes);
    if($uidattr === null)
    {
        // this person cannot be provisioned, because it is not complete
        $uidattr = Configure::read('scz.uid');
        CakeLog::write('error','zoneprovisioner: skipping COPerson "'.$provisioningData['CoPerson']['id'].'" because UID '.$uidattr.' is not present in the list of attributes');
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "CoPerson misses uid attribute '$uidattr'"));
        return true;
    }

    $coid = intval($provisioningData["Co"]["id"]);
    $person = $this->ZonePerson->find('first',array('conditions'=>array('uid'=>$uidattr, "co_id" => $coid)));
    if(empty($person)) {
      if($delete) {
        CakeLog::write('json_not',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "uid" => $uidattr,
                            "message" => "ZonePerson not found, end of delete operation"));
        // we are already done
        return TRUE;
      }

      try {
        $this->ZonePerson->save(array('uid'=>$uidattr, "co_id" => $coid, 'attributes'=>''));
        $person = $this->ZonePerson->find('first',array('conditions'=>array('id'=>$this->ZonePerson->id)));
      } catch(Exception $e) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "uid" => $uidattr,
                            "message" => "Error saving ZonePerson: ".$e->getMessage()));
        $person=null;
      }
    }

    if(empty($person)) {
      CakeLog::write('error','zoneprovisioner: cannot connect to remote database');
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "uid" => $uidattr,
                          "message" => "Cannot connect to remote database"));
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
    } else {
      $person['ZonePerson']['attributes']=$this->convertAttributes($attributes);
      if(isset($person['ZonePerson']['modified'])) {
        unset($person['ZonePerson']['modified']);
      }
      $person['ZoneService']=array_values($services);
      $this->ZonePerson->clear();
      $this->ZonePerson->saveAssociated($person,array('validate'=>FALSE));
    }
    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "uid" => $uidattr,
                        "message" => "end of provisioning"));

    return true;
  }

   /**
   * Determine the provisioning status of this target for a CO Person ID.
   *
   * @param  Integer CO Provisioning Target ID
   * @param  Model   $Model                  Model being queried for status (eg: CoPerson, CoGroup, CoEmailList)
   * @param  Integer $id                     $Model ID to check status for
   * @return Array ProvisioningStatusEnum, Timestamp of last update in epoch seconds, Comment
   */
  public function status($coProvisioningTargetId, $Model, $id)
  {
    $ret = array(
      'status'    => ProvisioningStatusEnum::Unknown,
      'timestamp' => null,
      'comment'   => ""
    );

    $config = Configure::load('scz','default');
     // Pull the object
    $provisioningData = $Model->find('first', array('conditions'=>array($Model->name.'.id'=>$id)));
    $attributes = $this->assembleAttributes($provisioningData);
    $uidattr = $this->getUID($attributes);
    $this->ZonePerson = ClassRegistry::init('ZoneProvisioner.ZonePerson');
    try {
      $person = $this->ZonePerson->find('first',array('conditions'=>array('uid'=>$uidattr, "co_id" => $provisioningData[$Model->name]['co_id'])));
    } catch(Exception $e) {
      $person=null;
    }

    if(!empty($person) && isset($person['ZonePerson'])) {
      $ret['timestamp'] = $person['ZonePerson']['modified'];
      $ret['status'] = ProvisioningStatusEnum::Provisioned;
      $ret['comment'] = $uidattr;
    } else {
      $ret['status'] = ProvisioningStatusEnum::NotProvisioned;
      $ret['comment'] = $uidattr;
    }
    return $ret;
  }

  /**
   * Create group entitlements based on group membership.
   * This is in addition to entitlements based on services.
   *
   * @param  Array array of group memberships
   * @return Array array of entitlement uris
   *
   * Entitlement uri's are constructed as follows:
   *  urn:mace:<base namespace>:group:<CO>:<group name>#<authority>
   * where base namespace and authority are configurable settings
   */
  private function mapGroupsToEntitlement($co, $groups) {
    $retval = array();
    $namespace = Configure::read('scz.namespace');
    $authority = Configure::read('scz.authority');

    if(!empty($groups)) {
      foreach($groups as $gm) {
        if(  isset($gm['member']) && $gm['member']
          && !empty($gm['CoGroup']['name'])) {
          // we need to url-encode the group name, but the group name contains a colon as
          // group separator that we would like to keep
          // Also, the namespace could contain spaces, but it also could contain escapable
          // characters. So we are going to urlencode the bunch, then translate back the colon
          //
          // To avoid encoding the pound sign for the authority, we encode that separately.
          $urn = urlencode("urn:mace:$namespace:".$co['name'].":".$gm['CoGroup']['name']);
          // replace the colon back
          $urn = str_replace("%3A",":",$urn);
          $retval[]=$urn."#".urlencode($authority);
        }
      }
    }
    return $retval;
  }

}
