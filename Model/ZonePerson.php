<?php
/**
 * COmanage Registry external ZonePerson Model
 *
 * SURFnet licenses this file to you under the Apache License, Version 2.0
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
 * @link          http://surfnet.nl
 * @package       comanage-scz
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */
App::uses("ZoneModel", "ZoneProvisioner.Model");
App::uses("ZoneService", "ZoneProvisioner.Model");

class ZonePerson extends ZoneModel {
  // Define class name for cake
  public $name = "ZonePerson";

  // Association rules from this model to other models
  public $hasAndBelongsToMany = array(
    'ZoneService' =>
      array(
        'className' => 'ZoneProvisioner.ZoneService',
        'joinTable' => 'zone_person_zone_service',
        'foreignKey' => 'zone_person_id',
        'associationForeignKey' => 'zone_service_id',
        'unique' => true,
      )
    );

  public $useDbConfig = "scz";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'notBlank',
      'required' => true,
      'message' => 'CO-id missing'
    ),
    'co_person_id' => array(
      'rule' => 'notBlank',
      'required' => true,
      'message' => 'COPerson-id missing'
    )
  );


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


  /**
   * Assemble attributes
   *
   * @param  Array                  $provisioningData         COPerson Data used for provisioning
   * @return Array Attribute data suitable for creating a ZonePerson
   * @throws UnderflowException
   */
  protected function assembleAttributes($provisioningData) {
    $configured_attributes=Configure::read('scz.attributes');

    // load external ldapschema plugins
    $this->plugins = $this->loadAvailablePlugins('ldapschema');

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
                $CPT = ClassRegistry::init('CoProvisioningTarget');
                $affilmap = $CPT->Co->CoExtendedType->affiliationMap($provisioningData['Co']['id']);

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
            $CPT = ClassRegistry::init('CoProvisioningTarget');
            $attributes[$export_name] = $CPT->Co
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
   * Provision a CoPerson to a ZonePerson
   *
   * @param  Array Attributes
   * @return String UID attribute value
   */
  public function provision($provisioningData, $delete, $actionid) {
    $attributes = $this->assembleAttributes($provisioningData);

    $uidattr = $this->getUID($attributes);

    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "uid" => $uidattr,
                        "co_person_id" => $provisioningData['CoPerson']['id'],
                        "message" => "Provisioning ZonePerson"));

    if($uidattr === null)
    {
      // this person cannot be provisioned, because it is not complete
      $uidattr = Configure::read('scz.uid');
      CakeLog::write('error','zoneprovisioner: skipping COPerson "'.$provisioningData['CoPerson']['id'].'" because UID '.$uidattr.' is not present in the list of attributes');
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "co_person_id" => $provisioningData['CoPerson']['id'],
                          "message" => "CoPerson misses uid attribute '$uidattr'"));
      return true;
    }

    $coid = intval($provisioningData["Co"]["id"]);
    try {
      $person = $this->find('first',array('conditions'=>array('co_person_id'=>$provisioningData['CoPerson']['id'])));
    }
    catch(Exception $e) {
      CakeLog::write('error','zoneprovisioner: cannot connect to remote database');
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "co_person_id" => $provisioningData['CoPerson']['id'],
                          "message" => "Cannot connect to remote database"));
      return true;
    }
    if(empty($person)) {
      if($delete) {
        CakeLog::write('json_not',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "ZonePerson not found, end of delete operation"));
        // we are already done
        return TRUE;
      }

      try {
        $this->clear();
        $this->save(array('uid'=>$uidattr, "co_id" => $coid, 'co_person_id' => $provisioningData['CoPerson']['id'], 'attributes'=>''));
        $person = $this->find('first',array('conditions'=>array('id'=>$this->id)));
      } catch(Exception $e) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "co_person_id" => $provisioningData['CoPerson']['id'],
                            "message" => "Error saving ZonePerson: ".$e->getMessage()));
        $person=null;
      }
    }

    if(empty($person)) {
      CakeLog::write('error','zoneprovisioner: cannot connect to remote database');
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "co_person_id" => $provisioningData['CoPerson']['id'],
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
      // would then update the new ZonePerson, but fail because the id is no longer present. This is of no
      // concern, as we were deleting the record anyway.
      $this->deleteAll(array('uid'=>$uidattr, "co_id" => $coid),true);
    } else {

      try {
        $services = $this->assembleServices($provisioningData);
        if(empty($services)) {
          CakeLog::write('json_err',array("module"=>"zone",
                              "action"=>"provision",
                              "id" => $actionid,
                              "co_person_id" => $provisioningData['CoPerson']['id'],
                              "message" => "no services configured"));
        }
      } catch(Exception $e) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "co_person_id" => $provisioningData['CoPerson']['id'],
                            "message" => "failed to assemble services: ".$e->getMessage()));
        $services=array();
      }

      $person['ZonePerson']['attributes']=$this->convertAttributes($attributes);
      if(isset($person['ZonePerson']['modified'])) {
        unset($person['ZonePerson']['modified']);
      }
      $person['ZoneService']=$services;
      $this->clear();
      $this->saveAssociated($person,array('validate'=>FALSE));
    }

    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "message" => "end of provisioning"));

  }

  public function status($id) {
    $ret = array(
      'status'    => ProvisioningStatusEnum::Unknown,
      'timestamp' => null,
      'comment'   => ""
    );

     // Pull the object
    try {
      $person = $this->find('first',array('conditions'=>array('co_person_id'=>$id)));
    } catch(Exception $e) {
      $person=null;
    }

    if(!empty($person) && isset($person['ZonePerson'])) {
      $ret['timestamp'] = $person['ZonePerson']['modified'];
      $ret['status'] = ProvisioningStatusEnum::Provisioned;
      $ret['comment'] = $person['ZonePerson']['uid'];
    } else {
      $ret['status'] = ProvisioningStatusEnum::NotProvisioned;
      $ret['comment'] = _txt('er.co_zone_provisioner.unprovisioned');
    }
    return $ret;
  }
}
