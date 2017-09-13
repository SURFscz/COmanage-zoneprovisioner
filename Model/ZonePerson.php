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

class ZonePerson extends AppModel {
  // Define class name for cake
  public $name = "ZonePerson";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $hasAndBelongsToMany = array(
    'ZoneService' =>
      array(
        'className' => 'ZoneService',
        'joinTable' => 'zone_person_zone_service',
        'foreignKey' => 'zone_person_id',
        'associationForeignKey' => 'zone_service_id',
        'unique' => true,
      )
    );

  public $useDbConfig = "scz";

  // Validation rules for table elements
  public $validate = array(
    'attributes' => array(
      'rule' => 'string',
      'required' => true,
      'message' => 'Attribute definitions missing'
    )
  );


}
