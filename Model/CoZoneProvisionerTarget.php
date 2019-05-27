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
    $delete = false;
    $person = false;
    $service = false;
    $actionid=uniqid();
    $model="CoPerson";

    if(isset($provisioningData["CoPerson"])) {
      $service = false;
      $person = true;
    }
    if(isset($provisioningData["CoService"])) {
      $service = true;
      $person = false;
      $model="CoService";
    }
    // skip provisioning of unrelated models
    if(!$person && !$service) {
      return TRUE;
    }

    $action="";
    switch($op) {
      case ProvisioningActionEnum::CoPersonAdded:
      case ProvisioningActionEnum::CoPersonUnexpired:
      case ProvisioningActionEnum::CoPersonPetitionProvisioned:
      case ProvisioningActionEnum::CoPersonPipelineProvisioned:
      case ProvisioningActionEnum::CoPersonReprovisionRequested:
      case ProvisioningActionEnum::CoPersonUpdated:
        if(!$person) {
          CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "CoPerson operation without CoPerson data"));
          return true;
        }
        if($provisioningData['CoPerson']['status'] == StatusEnum::Active) {
          $delete = false;
          $action="Adding user ".generateCn($provisioningData['PrimaryName']);
        } else {
          $delete=true;
          $action="Removing user ".generateCn($provisioningData['PrimaryName']);
        }
        break;
      case ProvisioningActionEnum::CoPersonDeleted:
      case ProvisioningActionEnum::CoPersonExpired:
      case ProvisioningActionEnum::CoPersonEnteredGracePeriod:
        if(!$person) {
          CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "CoPerson operation without CoPerson data"));
          return true;
        }
        $delete = true;
        $action="Removing user ".generateCn($provisioningData['PrimaryName']);
        break;
      case ProvisioningActionEnum::CoServiceAdded:
      case ProvisioningActionEnum::CoServiceUpdated:
      case ProvisioningActionEnum::CoServiceReprovisionRequested:
        if(!$service) {
          CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "CoService operation without CoService data"));
          return true;
        }
        if($provisioningData['CoService']['status'] == StatusEnum::Active) {
          $delete = false;
          $action="Adding service ".$provisioningData['CoService']['name'];
        } else {
          $delete=true;
          $action="Removing service ".$provisioningData['CoService']['name'];
        }
        break;
      case ProvisioningActionEnum::CoServiceDeleted:
        if(!$service) {
          CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "CoService operation without CoService data"));
          return true;
        }
        $delete = true;
        $action="Removing service ".$provisioningData['CoService']['name'];
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
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "Unimplemented operation for CoPerson or CoService model"));
        throw new RuntimeException("Not Implemented");
        break;
    }

    $config = Configure::load('scz','default');

    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "operation" => $op,
                        "message" => $action,
                        "co_id"=>$provisioningData[$model]["co_id"]));

    if($person) {
      $this->ZonePerson = ClassRegistry::init('ZoneProvisioner.ZonePerson');
      $this->ZonePerson->provision($provisioningData, $delete, $actionid);
    }
    else if($service) {
      $this->ZoneService = ClassRegistry::init('ZoneProvisioner.ZoneService');
      $this->ZoneService->provision($provisioningData, $delete, $actionid);
    }

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
    if($Model->name == 'CoPerson') {
      $this->ZonePerson = ClassRegistry::init('ZoneProvisioner.ZonePerson');
      return $this->ZonePerson->status($id);
    }
    else if($Model->name == "CoService") {
      $this->ZoneService = ClassRegistry::init('ZoneProvisioner.ZoneService');
      return $this->ZoneService->status($id);
    }
  }
}
