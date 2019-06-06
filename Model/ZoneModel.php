<?php
/**
 * COmanage Registry external ZoneModel base Model
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

class ZoneModel extends AppModel {

  // Add behaviors
  public $actsAs = array('Containable','ZoneProvisioner.Role');

  /**
   * Find services accessible to a given CoPersonId
   *
   * @returns Array List of CoServices
   */
  public function findServicesByPerson($coId, $coPersonId=null, $couId=null) {
    $visibility = array(VisibilityEnum::Unauthenticated);
    $groups = null;
    $CPT = ClassRegistry::init('CoProvisioningTarget');

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
        $groups = $CPT->Co->CoGroup->findForCoPerson($coPersonId, null, null, null, false);
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
    $services = $CPT->Co->CoService->find('all', $args);
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
   * Find services accessible to a given CoPersonId
   *
   * @returns Array List of CoServices
   */
  public function findPeopleByService($coService=null) {
    // find all people part of both the COU (or CO) and part of the CoGroup (if any).
    // Then filter these people based on the visibility of the Service
    $CPT = ClassRegistry::init('CoProvisioningTarget');

    $cou = null;
    if(!empty($coService['CoService']['cou_id'])) {
      $args=array();
      $args['contain']=false;
      $args['conditions']['Cou.id']=$coService['CoService']['cou_id'];
      $cou = $CPT->Co->Cou->find('first',$args);
      if(empty($cou)) $cou=null;
    }
    
    $args=array();
    $args['contain'] = FALSE;
    $args['conditions']['CoPerson.co_id'] = $coService['CoService']['co_id'];
    if(!empty($coService['CoService']['co_group_id'])) {
      $args['joins'][0]['table'] = 'cm_co_group_members';
      $args['joins'][0]['alias'] = 'CoGroupMember';
      $args['joins'][0]['type'] = 'INNER';
      $args['joins'][0]['conditions'][0] = 'CoGroupMember.co_person_id=CoPerson.id';
      $args['joins'][0]['conditions'][1] = array('OR'=>array(
        'CoGroupMember.valid_from is NULL',
        'CoGroupMember.valid_from < ' => date('Y-m-d H:i:s', time())));
      $args['joins'][0]['conditions'][2] = array('OR'=>array(
        'CoGroupMember.valid_through is NULL',
        'CoGroupMember.valid_through > ' => date('Y-m-d H:i:s', time())));
      $args['joins'][0]['conditions'][3] = array('NOT'=>array('CoGroupMember.deleted' => TRUE));

      $args['joins'][1]['table'] = 'cm_co_groups';
      $args['joins'][1]['alias'] = 'CoGroup';
      $args['joins'][1]['type'] = 'INNER';
      $args['joins'][1]['conditions'][0] = 'CoGroup.id = CoGroupMember.co_group_id';
      $args['joins'][1]['conditions'][1] =array('CoGroup.status' => SuspendableStatusEnum::Active);

      $args['conditions']['CoGroup.id']=$coService['CoService']['co_group_id'];

      if($cou !== null) {
        $args["conditions"]['CoGroup.cou_id'] = $cou['Cou']['id'];
      }
    }

    $filtered=array();
    $people = $CPT->Co->CoPerson->find('all', $args);
    
    // now filter on visibility
    $visibility = $coService['CoService']['visibility'];
    foreach($people as $person) {
      switch($visibility)
      {
        case VisibilityEnum::CoAdmin:
          if($this->isCoAdmin($person['CoPerson']['id'], $coService['CoService']['co_id'])) {
            $filtered[]=$person;
          }
          break;
        case VisibilityEnum::CoGroupMember:         
        case VisibilityEnum::CoMember:
        case VisibilityEnum::Unauthenticated:
          // all CoPerson are always member and also 'Unauthenticated',
          // so we can always accept both these conditions
          // if visibility is CoGroupMember AND a group is actually set,
          // we already filtered out all people member of that specific group
          $filtered[]=$person;
          break;
        default:
          // skip this
          break; 
      }
    }
    return $filtered;
  }

  /**
   * Assemble services
   *
   * @param  Array $provisioningData COPerson Data used for provisioning
   * @return Array List of service IDs
   */
  public function assembleServices($person) {

    $coid=$person["Co"]["id"];
    $services = $this->findServicesByPerson($coid,$person["CoPerson"]["id"],false);

    $cids=array();
    foreach($services as $service) {
      $cids[] = $service['CoService']['id'];
    }

    // Check these services exists as ZoneService. We need to filter out inactive or unprovisioned
    // services.
    $zs = ClassRegistry::init('ZoneProvisioner.ZoneService');
    $services=$zs->find('all',array('contain'=>FALSE, 'conditions'=> array('co_service_id' => $cids, 'co_id' => $coid)));
    $ids=array();
    foreach($services as $service) {
      $ids[]=$service['ZoneService']['id'];
    }
    
    return array("ZoneService"=>$ids);
  }

  /**
   * Assemble people
   *
   * @param  Array $provisioningData  COService Data used for provisioning
   * @return Array List of people IDs
   */
  public function assemblePeople($service) {
    $people = $this->findPeopleByService($service);

    $cids=array();
    foreach($people as $person) {
      $cids[] = $person['CoPerson']['id'];
    }

    $ids=array();

    // Check these people exists as ZonePerson. We need to filter out inactive or unprovisioned
    // people
    if(sizeof($cids) > 0) {
      $zp = ClassRegistry::init('ZoneProvisioner.ZonePerson');
      $zp->contain(FALSE);
      $zonepeople=$zp->find('all',array('conditions'=> array('co_person_id' => $cids)));
      if(!empty($zonepeople)) {
        foreach($zonepeople as $person) {
          $ids[]=$person['ZonePerson']['id'];
        }
      }
    }

    return array("ZonePerson"=>$ids);
  }
}
