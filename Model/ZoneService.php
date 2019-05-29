<?php
/**
 * COmanage Registry external ZoneService Model
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
App::uses("ZonePerson", "ZoneProvisioner.Model");

class ZoneService extends ZoneModel {
  // Define class name for cake
  public $name = "ZoneService";

  // Association rules from this model to other models
  public $hasAndBelongsToMany = array(
    'ZonePerson' =>
      array(
        'className' => 'ZoneProvisioner.ZonePerson',
        'joinTable' => 'zone_person_zone_service',
        'foreignKey' => 'zone_service_id',
        'associationForeignKey' => 'zone_person_id',
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
    'co_service_id' => array(
      'rule' => 'notBlank',
      'required' => true,
      'message' => 'COPerson-id missing'
    )
  );


  /**
   * Get Service metadata
   * Services contain 3 fields for URLs: service_url, service_label and entitlement_uri
   * The service_label is the field we want, but we used entitlement_uri in the past and
   * service_url was introduced before service_label
   *
   * @param  Array service Service object
   * @return String service metadata url
   */
  public function serviceMetadata($service) {
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
   * Assemble attributes
   *
   * @param  Array                  $provisioningData         CoService Data used for provisioning
   * @return Array Attribute data suitable for creating a ZoneService
   */
  protected function assembleAttributes($service) {
    return array(
            'service_url' => $service['CoService']['service_url'],
            'entitlement_uri' => $service['CoService']['entitlement_uri']
            );
  }


  /**
   * Convert the list of attributes to something we can use in the ZoneService attribute field
   *
   * @param  Array Attribute list
   * @return String Attribute field content
   */
  private function convertAttributes($attributes) {
    return json_encode($attributes);
  }

  /**
   * Provision a CoService to a ZoneService
   *
   * @param  Array Attributes
   * @return String UID attribute value
   */
  public function provision($provisioningData, $delete, $actionid) {

    $attributes = $this->assembleAttributes($provisioningData);
    $metadata = $this->serviceMetadata($provisioningData['CoService']);
    $service = $this->find('first',array('conditions'=>array('co_service_id'=>$provisioningData['CoService']['id'])));
    $coid = $provisioningData['CoService']['co_id'];

    if(empty($service)) {
      if($delete) {
        CakeLog::write('json_not',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "metadata" => $metadata,
                            "message" => "ZoneService not found, end of delete operation"));
        // we are already done
        return TRUE;
      }

      try {
        $this->save(array('metadata'=>$metadata, "co_id" => $coid, 'co_service_id'=>$provisioningData['CoService']['id'], 'attributes'=>''));
        $service = $this->find('first',array('conditions'=>array('id'=>$this->id)));
      } catch(Exception $e) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "metadata" => $metadata,
                            "message" => "Error saving ZoneService: ".$e->getMessage()));
        $service=null;
      }
    }

    if(empty($service)) {
      CakeLog::write('error','zoneprovisioner: cannot connect to remote database');
      CakeLog::write('json_err',array("module"=>"zone",
                          "action"=>"provision",
                          "id" => $actionid,
                          "metadata" => $metadata,
                          "message" => "Cannot connect to remote database"));
      throw new RuntimeException("Error writing to database");
    }

    if($delete) {
      // to maintain database integrity, we deleteAll based on the metadata, to avoid situations where we might
      // have duplicates. This would only occur if we have 2 simultaneous provisioning operations, where
      // one is adding and the other is deleting the ZonePerson. By removing all ZonePersons here, there
      // is only a problem when a user is deprovisioned and provisioned at the same time, which should never
      // occur, because deprovisioning has to finish before provisioning can take place.
      // Theoretically, if we deprovision a user and at the same time a slower process is changing something
      // to that user, the delete operation could have been performed before the change was done. The change
      // would then update the new ZonePerson, but fail because the id is no longer present. This is of no
      // concern, as we were deleting the record anyway.
      $this->deleteAll(array('metadata'=>$metadata, "co_id" => $coid),true);
    } else {

      try {
        $people = $this->assemblePeople($provisioningData);
      } catch(Exception $e) {
        CakeLog::write('json_err',array("module"=>"zone",
                            "action"=>"provision",
                            "id" => $actionid,
                            "message" => "failed to assemble people for servics: ".$e->getMessage()));
        $people=array();
      }

      $service['ZoneService']['metadata']=$metadata;
      $service['ZoneService']['attributes']=$this->convertAttributes($attributes);
      if(isset($service['ZoneService']['modified'])) {
        unset($service['ZoneService']['modified']);
      }
      $service['ZonePerson']=$people;
      $this->clear();
      $this->saveAssociated($service,array('validate'=>FALSE));
    }

    CakeLog::write('json_not',array("module"=>"zone",
                        "action"=>"provision",
                        "id" => $actionid,
                        "uid" => $this->serviceMetadata($service),
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
      $service = $this->find('first',array('conditions'=>array('co_service_id'=>$id)));
    } catch(Exception $e) {
      $service=null;
    }

    if(!empty($service) && isset($service['ZoneService'])) {
      $ret['timestamp'] = $service['ZoneService']['modified'];
      $ret['status'] = ProvisioningStatusEnum::Provisioned;
      $ret['comment'] = $service['ZoneService']['metadata'];
    } else {
      $ret['status'] = ProvisioningStatusEnum::NotProvisioned;
      $ret['comment'] = _txt('er.co_zone_provisioner.unprovisioned');
    }
    return $ret;
  }

}
