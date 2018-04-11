<?php
/**
 * COmanage Registry Role Behavior
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
 * @package       registry
 * @since         COmanage Registry vTODO
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 *
 * This Behavior class is a hack to allow a Model to determine COmanage role
 * types. This is/was located in the Controller RoleComponent, but that is not
 * accessible by Models. To be able to determine access control based on existing
 * roles, we need a similar implementation for Models, hence as behavior.
 *
 * Centralising this in some sort of helper, or a RoleModel of itself, would be
 * preferrable.
 */

App::uses('ModelBehavior', 'Model');

class RoleBehavior extends ModelBehavior {
    // Cache of checks we've already performed
    private $cache = array();

    /**
   * Determine what CO Enrollment Flows a CO Person may approve. Note this function
   * will only return enrollment flows where the CO Person is an approver by way
   * of group membership. CO/COU Admins will return empty lists.
   *
   * @since  COmanage Registry v0.8.3
   * @param  Integer CO Person ID
   * @return Array List CO Enrollment Flow IDs
   * @throws InvalidArgumentException
   */

    public function approverFor(Model $model, $coPersonId) {
        if(!$coPersonId) {
            return array();
        }

        // Use a join to pull enrollment flows where $coPersonId is in the approver group

        $CoEnrollmentFlow = ClassRegistry::init('CoEnrollmentFlow');

        $args = array();
        $args['fields'][] = 'CoEnrollmentFlow.id';
        $args['joins'][0]['table'] = 'co_group_members';
        $args['joins'][0]['alias'] = 'CoGroupMember';
        $args['joins'][0]['type'] = 'INNER';
        $args['joins'][0]['conditions'][0] = 'CoEnrollmentFlow.approver_co_group_id=CoGroupMember.co_group_id';
        $args['conditions']['CoGroupMember.co_person_id'] = $coPersonId;
        $args['contain'] = false;

        $efs = $CoEnrollmentFlow->find('list', $args);

        // find() will return id => id with only one field specified, so just pull the keys
        return array_keys($efs);
    }

    /**
   * Determine what CO Enrollment Flows an Org Identity may approve. Note unlike
   * approverFor this function WILL return enrollment flows where the CO Person is
   * an approver by way of group membership.
   *
   * @since  COmanage Registry v0.9.4
   * @param  Array Array of Org Identity IDs
   * @return Array List CO Enrollment Flow IDs
   */

    public function approverForByOrgIdentities(Model $model, $orgIdentityIds) {
        $ret = array();

        if(empty($orgIdentityIds)) {
            return $ret;
        }

        // 1 - Pull COs and CO Person IDs for each $orgIdentityId

        $CoOrgIdentityLink = ClassRegistry::init('CoOrgIdentityLink');

        $args = array();
        $args['conditions']['CoOrgIdentityLink.org_identity_id'] = $orgIdentityIds;
        $args['conditions']['CoPerson.status'] = StatusEnum::Active;
        $args['contain'][] = 'CoPerson';

        $links = $CoOrgIdentityLink->find('all', $args);

        // 2 - For each CO Person ID, if an admin or cou admin pull all enrollment flows
        // for the specified ID. for now, we treat COU admins and CO admins the same
        // -- any can see any petition within the CO. If not an admin, then determine
        // which flows the person can see.

        $CoEnrollmentFlow = ClassRegistry::init('CoEnrollmentFlow');

        foreach($links as $l) {
            if($this->isCoOrCouAdmin($l['CoPerson']['id'], $l['CoPerson']['co_id'])) {
                $args = array();
                $args['fields'][] = 'CoEnrollmentFlow.id';
                $args['conditions']['CoEnrollmentFlow.co_id'] = $l['CoPerson']['co_id'];
                $args['contain'] = false;

                $efs = $CoEnrollmentFlow->find('list', $args);

                // find() will return id => id with only one field specified, so just pull the keys
                $ret = array_merge($ret, array_keys($efs));
            } else {
                $ret = array_merge($ret, $this->approverFor($model, $l['CoPerson']['id']));
            }
        }

        return $ret;
    }

    /**
   * Cached CO ID lookup.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID
   * @return Integer CO ID for CO Person ID
   * @throws InvalidArgumentException
   */

    protected function cachedCoIdLookup($coPersonId) {
        if(!$coPersonId) {
            return false;
        }

        if(isset($this->cache['coperson'][$coPersonId]['co_id'])) {
            return $this->cache['coperson'][$coPersonId]['co_id'];
        }

        $CoPerson = ClassRegistry::init('CoPerson');

        $coId = $CoPerson->field('co_id', array('CoPerson.id' => $coPersonId));

        if(!$coId) {
            throw new InvalidArgumentException(_txt('er.cop.unk-a', array($coPersonId)));
        }

        $this->cache['coperson'][$coPersonId]['co_id'] = $coId;

        return $coId;
    }

    /**
   * Cached CO ID lookup by CO Group.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Group ID
   * @return Integer CO ID for CO Group ID
   * @throws InvalidArgumentException
   */

    protected function cachedCoIdLookupByCoGroup($coGroupId) {
        if(!$coGroupId) {
            return false;
        }

        if(isset($this->cache['cogroup'][$coGroupId]['co_id'])) {
            return $this->cache['cogroup'][$coGroupId]['co_id'];
        }

        $CoGroup = ClassRegistry::init('CoGroup');

        $coId = $CoGroup->field('co_id', array('CoGroup.id' => $coGroupId));

        if(!$coId) {
            throw new InvalidArgumentException(_txt('er.gr.nf', array($coGroupId)));
        }

        $this->cache['cogroup'][$coGroupId]['co_id'] = $coId;

        return $coId;
    }

    /**
   * Internal function to handle a cached group membership check.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  String Group name or SQL pattern to check
   * @param  String SQL parameter (eg: "LIKE") to use in search conditions
   * @param  Integer CO Group ID
   * @param  GroupEnum Group Type
   * @param  Integer COU ID, or null for no COU ID (ie: CO level groups only), or true for any COU ID
   * @param  Boolean Check for ownership instead of membership
   * @return Boolean True if the CO Person is in the matching group, false otherwise
   */

    protected function cachedGroupCheck($coPersonId,
                                        $groupName="",
                                        $searchParam="",
                                        $groupId=null,
                                        $owner=false,
                                        $groupType=null,
                                        $couId=null) {
        // Since cachedGroupGet is also cached, we don't need to do another cache here

        $groups = $this->cachedGroupGet($coPersonId, $groupName, $searchParam, $groupId, $owner, $groupType, $couId);

        return (boolean)count($groups);
    }

    /**
   * Internal function to handle a cached group membership get.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID
   * @param  String Group name or SQL pattern to check
   * @param  String SQL parameter (eg: "LIKE") to use in search conditions
   * @param  Integer CO Group ID
   * @param  Boolean Check for ownership instead of membership
   * @param  Integer COU ID, or null for no COU ID (ie: CO level groups only), or true for any COU ID
   * @param  GroupEnum Group Type
   * @return Array Array of CO Groups as returned by find()
   */

    protected function cachedGroupGet($coPersonId,
                                      $groupName="",
                                      $searchParam="",
                                      $groupId=null,
                                      $owner=false,
                                      $groupType=null,
                                      $couId=null) {
        if(!$coPersonId) {
            return false;
        }

        // First check the cache (note: $condKey is something like "CoGroup.name LIKE")

        $condKey = null;
        $condValue = null;
        $groupRole = ($owner ? 'owner' : 'member');

        if($groupName != "") {
            $condKey = 'CoGroup.name' . ($searchParam != "" ? (" " . $searchParam) : "");
            $condValue = $groupName;
        } elseif($groupId != null) {
            $condKey = 'CoGroup.id';
            $condValue = $groupId;
        } elseif($groupType != null) {
            $condKey = 'CoGroup.group_type';
            $condValue = $groupType;
        }

        // We need to use the couId as an element in the caching, in particular for
        // repeated calls from isCoOrCouAdmin()
        $condCou = 0;

        if($couId) {
            if($couId === true) {
                $condCou = -1;
            } else {
                $condCou = $couId;
            }
        }
        if(isset($this->cache['coperson'][$coPersonId][$condKey][$condValue][$condCou][$groupRole])) {
            return $this->cache['coperson'][$coPersonId][$condKey][$condValue][$condCou][$groupRole];
        }

        $CoGroup = ClassRegistry::init('CoGroup');

        $args = array();
        $args['joins'][0]['table'] = 'co_group_members';
        $args['joins'][0]['alias'] = 'CoGroupMember';
        $args['joins'][0]['type'] = 'INNER';
        $args['joins'][0]['conditions'][0] = 'CoGroup.id=CoGroupMember.co_group_id';
        if($condValue != null) {
            $args['conditions'][$condKey] = $condValue;
        }
        if($couId === true) {
            $args['conditions'][] = 'CoGroup.cou_id IS NOT NULL';
        } elseif($couId) {
            $args['conditions']['CoGroup.cou_id'] = $couId;
        } else {
            $args['conditions']['CoGroup.cou_id'] = null;
        }
        $args['conditions']['CoGroup.status'] = StatusEnum::Active;
        $args['conditions']['CoGroupMember.co_person_id'] = $coPersonId;
        $args['conditions']['CoGroupMember.'.$groupRole] = 1;
        $args['contain'] = false;
        $groups = $CoGroup->find('all', $args);

        // Add this result to the cache

        $this->cache['coperson'][$coPersonId][$condKey][$condValue][$condCou][$groupRole] = $groups;
        return $groups;
    }

    /**
   * Internal function to handle a cached person role check.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID
   * @param  Integer COU ID
   * @param  Boolean Whether to check only active (including grace period) roles or all roles
   * @return Boolean True if the CO Person has a matching role, false otherwise
   */

    protected function cachedPersonRoleCheck($coPersonId, $coId, $couId=null, $active=true) {
        if(!$coPersonId || !$coId) {
            return false;
        }

        // First check the cache

        if($couId) {
            if(isset($this->cache['coperson'][$coPersonId][$coId]['CouPerson'][$couId])) {
                return $this->cache['coperson'][$coPersonId][$coId]['CouPerson'][$couId];
            }
        } else {
            if(isset($this->cache['coperson'][$coPersonId][$coId]['CoPerson'])) {
                return $this->cache['coperson'][$coPersonId][$coId]['CoPerson'];
            }
        }

        $CoPerson = ClassRegistry::init('CoPerson');

        $args = array();
        $args['joins'][0]['table'] = 'co_person_roles';
        $args['joins'][0]['alias'] = 'CoPersonRole';
        $args['joins'][0]['type'] = 'INNER';
        $args['joins'][0]['conditions'][0] = 'CoPerson.id=CoPersonRole.co_person_id';
        $args['conditions']['CoPerson.id'] = $coPersonId;
        $args['conditions']['CoPerson.co_id'] = $coId;
        if($active) {
            $args['conditions']['CoPerson.status'] = array(StatusEnum::Active, StatusEnum::GracePeriod);
            $args['conditions']['CoPersonRole.status'] = array(StatusEnum::Active, StatusEnum::GracePeriod);
        }
        if($couId) {
            $args['conditions']['CoPersonRole.cou_id'] = $couId;
        }
        $args['contain'] = false;
        $member = $CoPerson->find('count', $args);

        // Add this result to the cache

        if($couId) {
            $this->cache['coperson'][$coPersonId][$coId]['CouPerson'][$couId] = (boolean)$member;
        } else {
            $this->cache['coperson'][$coPersonId][$coId]['CoPerson'] = (boolean)$member;
        }
        return (boolean)$member;
    }


    /**
   * Determine if a CO Person can request email verification for another CO Person.
   *
   * @since  COmanage Registry v0.8.4
   * @param  Integer CO Person ID of requestor
   * @param  Integer Email Address ID to request email verification of
   * @return Boolean True if email verification may be requested, false otherwise
   * @throws InvalidArgumentException
   */

    public function canRequestVerificationOfEmailAddress(Model $model, $coPersonId, $emailAddressId) {
        if(!$coPersonId) {
            // This is most likely a CMP admin who is not in the CO
            return false;
        }

        // First pull the email address
        $args = array();
        $args['conditions']['EmailAddress.id'] = $emailAddressId;
        $args['contain'] = false;

        $EmailAddress = ClassRegistry::init('EmailAddress');

        $ea = $EmailAddress->find('first', $args);

        if(empty($ea)) {
            throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.email_addresses.1'), $emailAddressId)));
        }

        // One can request their own verification

        if(!empty($ea['EmailAddress']['co_person_id'])
           && $coPersonId == $ea['EmailAddress']['co_person_id']) {
            return true;
        }

        if(!empty($ea['EmailAddress']['org_identity_id'])) {
            // See if the CO Person and org identity are linked (ie: are the same person)

            $Link = ClassRegistry::init('CoOrgIdentityLink');

            $args = array();
            $args['conditions']['CoOrgIdentityLink.co_person_id'] = $coPersonId;
            $args['conditions']['CoOrgIdentityLink.org_identity_id'] = $ea['EmailAddress']['org_identity_id'];
            $args['contain'] = false;

            if($Link->find('count', $args) > 0) {
                return true;
            }
        }

        // A CO or COU Admin can request verification

        if(!empty($ea['EmailAddress']['co_person_id'])
           && $this->isCoOrCouAdminForCoPerson($model, $coPersonId, $ea['EmailAddress']['co_person_id'])) {
            return true;
        }

        if(!empty($ea['EmailAddress']['org_identity_id'])
           && $this->isCoOrCouAdminForOrgIdentity($model, $coPersonId, $ea['EmailAddress']['org_identity_id'])) {
            return true;
        }

        return false;
    }

    /**
   * Determine what COUs a CO Person is a COU Admin for. Note this function will return
   * no COUs if the CO Person is a CO Admin but not a COU Admin.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID
   * @return Array List COU IDs and Names
   * @throws InvalidArgumentException
   */

    public function couAdminFor(Model $model, $coPersonId) {
        global $group_sep;

        $couNames = array();
        $childCous = array();

        if(!$coPersonId) {
            return array();
        }

        try {
            $coId = $this->cachedCoIdLookup($coPersonId);
        }
        catch(InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        // First pull the COUs $coPersonId is explicitly an admin for

        $couGroups = $this->cachedGroupGet($coPersonId, "", "", null, false, GroupEnum::Admins, true);

        // What we actually have are the groups associated with each COU for which
        // coPersonId is an admin.

        $Cou = ClassRegistry::init('Cou');

        foreach($couGroups as $couGroup) {
            if(!empty($couGroup['CoGroup']['cou_id'])) {
                // Pull the COU and its children (if any)

                try {
                    $childCous = array_unique($childCous + $Cou->childCousById($couGroup['CoGroup']['cou_id'], true));
                }
                catch(InvalidArgumentException $e) {
                    throw new InvalidArgumentException($e->getMessage());
                }
            }
        }

        return $childCous;
    }

    /**
   * Determine if an identifier is associated with an Administrator for any CO or COU.
   *
   * @since  COmanage Registry v0.8
   * @param  String Identifier
   * @param  String Type of check to perform ('coadmin' or 'couadmin')
   * @return Boolean True if the identifier is associated with a CO administrator, false otherwise
   * @todo   Honor identifier type
   * @throws InvalidArgumentException
   */

    protected function identifierIsAdmin($identifier, $adminType) {
        global $group_sep;
        if(!$identifier) {
            return false;
        }

        // First check the cache

        if(isset($this->cache['identifier'][$identifier][$adminType])) {
            return $this->cache['identifier'][$identifier][$adminType];
        }

        // Find the CO Person IDs for this identifier

        $CoPerson = ClassRegistry::init('CoPerson');

        $coPersonIds = null;
        $isAdmin = false;

        try {
            // XXX We should accept a configuration to specify which identifier type to be querying
            // (see also AppController::CalculateCMRoles)
            $coPersonIds = $CoPerson->idsForIdentifier($identifier, null, true);
        }
        catch(Exception $e) {
            // At the moment, an exception will just result in us returning false
            //throw new InvalidArgumentException($e->getMessage());
        }
        // We now have a list of CO Person IDs, and need to see if any of them are an admin

        if(!empty($coPersonIds)) {
            $args = array();
            $args['joins'][0]['table'] = 'co_group_members';
            $args['joins'][0]['alias'] = 'CoGroupMember';
            $args['joins'][0]['type'] = 'INNER';
            $args['joins'][0]['conditions'][0] = 'CoGroup.id=CoGroupMember.co_group_id';
            $args['conditions']['CoGroupMember.co_person_id'] = $coPersonIds;
            $args['conditions']['CoGroup.group_type'] = GroupEnum::Admins;
            if($adminType == 'coadmin') {
                $args['conditions']['CoGroup.cou_id'] = null;
            } else {
                $args['conditions'][] = 'CoGroup.cou_id IS NOT NULL';
            }
            $args['conditions']['CoGroup.status'] = StatusEnum::Active;
            $args['contain'] = false;
            $isAdmin = (boolean)$CoPerson->Co->CoGroup->find('count', $args);
        }

        // Cache the result
        $this->cache['identifier'][$identifier][$adminType] = $isAdmin;
        return $isAdmin;
    }

    /**
   * Determine if an identifier is associated with a CMP Administrator.
   *
   * @since  COmanage Registry v0.8
   * @param  String Identifier
   * @return Boolean True if the identifier is associated with a CMP administrator, false otherwise
   * @todo   Honor identifier type
   * @throws InvalidArgumentException
   */

    public function identifierIsCmpAdmin(Model $model, $identifier) {
        if(!$identifier) {
            return false;
        }

        // First check the cache

        if(isset($this->cache['identifier'][$identifier]['cmpadmin'])) {
            return $this->cache['identifier'][$identifier]['cmpadmin'];
        }

        // Find the CO Person IDs for this identifier

        $CoPerson = ClassRegistry::init('CoPerson');

        $coPersonIds = null;
        $coPerson = null;

        try {
            // XXX We should accept a configuration to specify which identifier type to be querying
            // (see also AppController::CalculateCMRoles)
            $coPersonIds = $CoPerson->idsForIdentifier($identifier, null, true);
        }
        catch(Exception $e) {
            // We probably have a newly enrolled person who has an identifier but not a full
            // record yet. (ie: petition confirmation.) Just return false.
            return false;
        }

        // We now have a list of CO Person IDs, and need to figure out which one correlates to the
        // COmanage CO.

        if(!empty($coPersonIds)) {
            $args = array();
            $args['joins'][0]['table'] = 'cos';
            $args['joins'][0]['alias'] = 'Co';
            $args['joins'][0]['type'] = 'INNER';
            $args['joins'][0]['conditions'][0] = 'CoPerson.co_id=Co.id';
            $args['conditions']['Co.name'] = 'COmanage';
            $args['conditions']['Co.status'] = StatusEnum::Active;
            $args['conditions']['CoPerson.id'] = $coPersonIds;
            $args['contain'] = false;

            $coPerson = $CoPerson->find('first', $args);
        }

        // Now that we have the right data, we can hand off to cachedGroupCheck.

        if(isset($coPerson['CoPerson'])) {
            $isAdmin = $this->cachedGroupCheck($coPerson['CoPerson']['id'], "", "", null, false, GroupEnum::Admins);

            // Cache the result
            $this->cache['identifier'][$identifier]['cmpadmin'] = $isAdmin;

            return $isAdmin;
        }

        return false;
    }

    /**
   * Determine if an identifier is associated with an Administrator for any CO.
   *
   * @since  COmanage Registry v0.8
   * @param  String Identifier
   * @return Boolean True if the identifier is associated with a CO administrator, false otherwise
   * @todo   Honor identifier type
   * @throws InvalidArgumentException
   */

    public function identifierIsCoAdmin(Model $model, $identifier) {
        return $this->identifierIsAdmin($identifier, 'coadmin');
    }

    /**
   * Determine if an identifier is associated with an Administrator for any COU.
   *
   * @since  COmanage Registry v0.8
   * @param  String Identifier
   * @return Boolean True if the identifier is associated with a CO administrator, false otherwise
   * @todo   Honor identifier type
   * @throws InvalidArgumentException
   */

    public function identifierIsCouAdmin(Model $model, $identifier) {
        return $this->identifierIsAdmin($identifier, 'couadmin');
    }

    /**
   * Determine if a CO Person has the ability to approve petitions for any enrollment flow.
   *
   * @since  COmanage Registry v0.8.3
   * @param  Integer CO Person ID
   * @return Boolean True if the CO Person is an approver for any enrollment flow, false otherwise
   */

    public function isApprover(Model $model, $coPersonId) {
        // First check the cache

        if(isset($this->cache['coperson'][$coPersonId]['co_ef']['approver'])) {
            return $this->cache['coperson'][$coPersonId]['co_ef']['approver'];
        }

        $ret = false;

        // Make sure we have a CO Person ID

        if(!$coPersonId) {
            // throw new InvalidArgumentException(_txt('er.cop.unk'));
            // Just return false in case we're in the middle of an authenticated self signup
            return false;
        }

        // Find the person's CO

        try {
            $coId = $this->cachedCoIdLookup($coPersonId);
        }
        catch(InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        // A person is an approver if
        // (1) they are a CO Admin or COU Admin (note they may not actually have the ability to approve anything)
        // (2) they are a member of any group that is an approver group for any enrollment flow in the CO

        if($this->isCoOrCouAdmin($model, $coPersonId, $coId)) {
            $ret = true;
        } else {
            // Pull groups associated with enrollment flows in $coID

            $args = array();
            $args['conditions']['CoEnrollmentFlow.co_id'] = $coId;
            $args['conditions']['CoEnrollmentFlow.status'] = StatusEnum::Active;
            $args['conditions'][] = 'CoEnrollmentFlow.approver_co_group_id IS NOT NULL';
            $args['fields'][] = 'DISTINCT (approver_co_group_id)';
            $args['contain'] = false;

            $CoEnrollmentFlow = ClassRegistry::init('CoEnrollmentFlow');

            $groups = $CoEnrollmentFlow->find('first', $args);

            foreach($groups as $g) {
                if(!empty($g['approver_co_group_id'])) {
                    if($this->isCoGroupMember($coPersonId, $g['approver_co_group_id'])) {
                        // Person is member of this group, so is an approver for at least one group
                        $ret = true;
                        break;
                    }
                }
            }
        }

        // Update the cache
        $this->cache['coperson'][$coPersonId]['co_ef']['approver'] = $ret;

        return $ret;
    }

    /**
   * Determine if a CO Person has the ability to approve petitions for the specified enrollment flow.
   *
   * @since  COmanage Registry v0.8.3
   * @param  Integer CO Person ID
   * @param  Integer CO Enrollment Flow ID
   * @param  Integer CO Petition ID
   * @return Boolean True if the CO Person is an approver for any enrollment flow, false otherwise
   * @throws InvalidArgumentException
   */

    public function isApproverForFlow(Model $model, $coPersonId, $coEfId, $coPetitionId=null) {
        // First check the cache

        if($coPetitionId) {
            if(isset($this->cache['coperson'][$coPersonId]['co_petition'][$coPetitionId]['approver'])) {
                return $this->cache['coperson'][$coPersonId]['co_petition'][$coPetitionId]['approver'];
            }
        } else {
            if(isset($this->cache['coperson'][$coPersonId]['co_ef'][$coEfId]['approver'])) {
                return $this->cache['coperson'][$coPersonId]['co_ef'][$coEfId]['approver'];
            }
        }

        $ret = false;

        // Make sure we have a CO Person ID

        if(!$coPersonId) {
            // throw new InvalidArgumentException(_txt('er.cop.unk'));
            // Just return false in case we're in the middle of an authenticated self signup
            return false;
        }

        // Try to find the enrollment flow

        $args = array();
        $args['conditions']['CoEnrollmentFlow.id'] = $coEfId;
        $args['conditions']['CoEnrollmentFlow.status'] = StatusEnum::Active;
        $args['contain'] = false;

        $CoEnrollmentFlow = ClassRegistry::init('CoEnrollmentFlow');

        $coEF = $CoEnrollmentFlow->find('first', $args);

        if(empty($coEF)) {
            throw new InvalidArgumentException(_txt('er.coef.unk'));
        }

        if($coEF['CoEnrollmentFlow']['approval_required']) {
            if(!empty($coEF['CoEnrollmentFlow']['approver_co_group_id'])) {
                // $coPersonId must be a member of this group

                if($this->isCoGroupMember($model, $coPersonId, $coEF['CoEnrollmentFlow']['authz_co_group_id'])) {
                    $ret = true;
                }
            } else {
                // If no group is defined, then we use the following logic:
                // (1) $coPersonId is a CO admin

                if($this->isCoAdmin($model, $coPersonId, $coEF['CoEnrollmentFlow']['co_id'])) {
                    $ret = true;
                } else {
                    if(!empty($coEF['CoEnrollmentFlow']['authz_cou_id'])) {
                        // (2) authz_cou_id is specified and $coPersonId is a COU admin for that COU

                        $ret = $this->isCouAdmin($model, $coPersonId, $coEF['CoEnrollmentFlow']['authz_cou_id']);
                    } else {
                        // No authz_cou_id

                        $couId = null;

                        if($coPetitionId) {
                            $couId = $CoEnrollmentFlow->CoPetition->field('cou_id',
                                                                          array('CoPetition.id' => $coPetitionId));
                        }

                        if($couId) {
                            // (3) A COU is attached to the petition and $coPersonId is a COU admin

                            $ret = $this->isCouAdmin($model, $coPersonId, $couId);
                        } else {
                            // (4) No authz_cou_id is specified and $coPersonId is a COU admin

                            $ret = $this->isCouAdmin($model, $coPersonId);
                        }
                    }
                }
            }
        } else {
            // No approval required
        }

        // Update the cache
        $this->cache['coperson'][$coPersonId]['co_ef'][$coEfId]['approver'] = $ret;

        return $ret;
    }

    /**
   * Determine if a CO Person is a CO Administrator.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID that CO Person is an Admin for, or null for any CO
   * @return Boolean True if the CO Person is a CO Administrator, false otherwise
   */

    public function isCoAdmin(Model $model, $coPersonId, $coId=null) {
        // A person is a CO Admin if they are a member of the "admin" group for the specified CO.

        if($coId) {
            // First check that $coPersonId is in $coId
            if(!$this->isCoPerson($model, $coPersonId, $coId)) {
                return false;
            }
        }

        return $this->cachedGroupCheck($coPersonId, "", "", null, false, GroupEnum::Admins);
    }

    /**
   * Determine if a CO Person is a CO Administrator for another CO Person.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential CO Admin
   * @param  Integer CO Person ID of subject
   * @param  Integer CO ID
   * @return Boolean True if the CO Person is a CO Administrator for the subject, false otherwise
   * @throws InvalidArgumentException
   */

    public function isCoAdminForCoPerson(Model $model, $coPersonId, $subjectCoPersonId) {
        // Look up the CO ID and hand off to the other checks

        try {
            $coId = $this->cachedCoIdLookup($coPersonId);
            $sCoId = $this->cachedCoIdLookup($subjectCoPersonId);
        }
        catch(InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        // Make sure both are in the same CO. We don't do a role check since a person
        // can be a CO admin over someone without any roles (unlike a COU admin).

        return (($coId == $sCoId) && $this->isCoAdmin($model, $coPersonId));
    }

    /**
   * Determine if a CO Person is a member of a CO Group.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID
   * @return Boolean True if the CO Person is a CO Administrator, false otherwise
   */

    public function isCoGroupMember(Model $model, $coPersonId, $coGroupId) {
        return $this->cachedGroupCheck($coPersonId, "", "", $coGroupId);
    }

    /**
   * Determine if a CO Person is a CO or COU Administrator.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID that CO Person is an Admin for, or null for any CO
   * @return Boolean True if the CO Person is a CO or COU Administrator, false otherwise
   */

    public function isCoOrCouAdmin(Model $model, $coPersonId, $coId=null) {
        // A person is a CO Admin if they are a member of the GroupEnum::Admins group for the specified CO.
        // A person is a COU Admin if they are a member of the GroupEnum::Admins group within the specified COU.

        global $group_sep;

        if($coId) {
            // First check that $coPersonId is in $coId

            if(!$this->isCoPerson($model, $coPersonId, $coId)) {
                return false;
            }
        }

        // For code readability, we do this as separate checks rather than passing an OR
        // condition to cachedGroupCheck(). This may result in two DB calls, but it may not
        // since chances are we've already cached the results to isCoAdmin() (if we're being
        // called from CoEnrollmentFlow::authorize(), at least).

        if($this->cachedGroupCheck($coPersonId, "", "", null, false, GroupEnum::Admins)) {
            return true;
        }

        return $this->cachedGroupCheck($coPersonId, "", "", null, false, GroupEnum::Admins, true);
    }

    /**
   * Determine if a CO Person is a CO or COU Administrator for another CO Person.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential CO(U) Admin
   * @param  Integer CO Person ID of subject
   * @return Boolean True if the CO Person is a CO or COU Administrator for the subject, false otherwise
   */

    public function isCoOrCouAdminForCoPerson(Model $model, $coPersonId, $subjectCoPersonId) {
        if($this->isCoAdminForCoPerson($model, $coPersonId, $subjectCoPersonId)) {
            return true;
        } else {
            return $this->isCouAdminForCoPerson($model, $coPersonId, $subjectCoPersonId);
        }
    }

    /**
   * Determine if a CO Person is a CO(U) Administrator for a CO Person Role.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential CO(U) Admin
   * @param  Integer CO Person Role ID of subject
   * @return Boolean True if the CO Person is a CO(U) Administrator for the subject, false otherwise
   */

    public function isCoOrCouAdminForCoPersonRole(Model $model, $coPersonId, $subjectCoPersonRoleId) {
        if(!$coPersonId) {
            return false;
        }

        // Look up the CO Person ID for the subject and then hand off the request.

        $CoPersonRole = ClassRegistry::init('CoPersonRole');

        $args = array();
        $args['conditions']['CoPersonRole.id'] = $subjectCoPersonRoleId;
        $args['contain'] = false;

        $copr = $CoPersonRole->find('first', $args);

        if($copr && isset($copr['CoPersonRole']['co_person_id'])) {
            return $this->isCoOrCouAdminForCoPerson($model, $coPersonId, $copr['CoPersonRole']['co_person_id']);
        } else {
            return false;
        }
    }

    /**
   * Determine if a CO Person is a CO(U) Administrator for an Org Identity.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential CO(U) Admin
   * @param  Integer Org Identity ID of subject
   * @param  String Authenticated user ID, for use when org identities are pooled and there is no CO Person ID in context
   * @return Boolean True if the CO Person is a CO(U) Administrator for the subject, false otherwise
   */

    public function isCoOrCouAdminForOrgIdentity(Model $model, $coPersonId, $subjectOrgIdentityId, $identifier=null) {
        // A person is an admin if org identities are pooled or if the subject and the CO person
        // are in the CO. First check that they're even an admin at all.

        $CmpEnrollmentConfiguration = ClassRegistry::init('CmpEnrollmentConfiguration');

        $pool = $CmpEnrollmentConfiguration->orgIdentitiesPooled();

        if($pool && $identifier) {
            // If org identities are pooled then we can't have a $coPersonId in context.
            // Look up the identifier and see if they are an admin of some sort.

            return $this->identifierIsCoAdmin($model, $identifier) || $this->identifierIsCouAdmin($model, $identifier);
        }

        if(!$coPersonId || !$subjectOrgIdentityId) {
            return false;
        }

        if($this->isCoAdmin($model, $coPersonId)
           || $this->isCouAdmin($model, $coPersonId)) {
            if($pool) {
                // All CO and COU Admins can manage all org identities. We probably won't get
                // here since this scenario should have been caught above.

                return true;
            } else {
                // Is $subjectOrgIdentityId in $coPersonId's CO?

                $OrgIdentity = ClassRegistry::init('OrgIdentity');

                $subjectCoId = $OrgIdentity->field('co_id', array('OrgIdentity.id' => $subjectOrgIdentityId));

                if($subjectCoId && ($subjectCoId == $this->cachedCoIdLookup($coPersonId))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
   * Determine if a person is in a CO. A person is a CO Person if they have at least one
   * valid role within the CO.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID
   * @param  Boolean Require active role
   * @return Boolean True if the person is in the CO, false otherwise
   */

    public function isCoPerson(Model $model, $coPersonId, $coId, $requireRole=true) {
        if($requireRole) {
            return $this->cachedPersonRoleCheck($coPersonId, $coId, null, true);
        } else {
            // What's supposed to go here?
            throw new InternalErrorException("Not implemented (isCoPerson)");
        }
    }

    /**
   * Determine if a CO Person is a COU Administrator for a specified COU. Note this function
   * will return false if CO Person is a CO Administrator, but not a COU Administrator.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer COU ID, or NULL to determine if a COU Admin for any COU
   * @return Boolean True if the CO Person is a COU Administrator for the specified COU, false otherwise
   */

    public function isCouAdmin(Model $model, $coPersonId, $couId=null) {
        // A person is a COU Admin if they are a member of the "admin:COU Name" group within the specified CO,
        // or a member of the admin group for any parent COU.

        global $group_sep;

        if($couId) {
            $Cou = ClassRegistry::init('Cou');

            // Get a listing of this COU and its parents.

            $cous = $Cou->getPath($couId);

            if(!empty($cous)) {
                // This will be in order from the top of the tree down to $couId, but
                // for our purposes it doesn't matter where we find the admin membership

                foreach($cous as $c) {
                    if(!empty($c['Cou']['id'])
                       && $this->cachedGroupCheck($coPersonId, "", "", null, false, GroupEnum::Admins, $c['Cou']['id'])) {
                        return true;
                    }
                }
            }

            // If we get here we've run out of things to check
            return false;
        } else {
            // We don't need to walk the tree since we only care if a person is a COU Admin
            // for *any* group, not which groups (which would require getting the child COUs).

            return $this->cachedGroupCheck($coPersonId, "", "", null, false, GroupEnum::Admins, true);
        }
    }

    /**
   * Determine if a CO Person is a COU Administrator for another CO Person.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential COU Admin
   * @param  Integer CO Person ID of subject
   * @return Boolean True if the CO Person is a COU Administrator for the subject, false otherwise
   * @throws InvalidArgumentException
   */

    public function isCouAdminForCoPerson(Model $model, $coPersonId, $subjectCoPersonId) {
        if(!$coPersonId) {
            return false;
        }

        // Find the person's CO

        try {
            $coId = $this->cachedCoIdLookup($coPersonId);
        }
        catch(InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        // Next, pull the COUs for which $coPersonId is a COU admin
        $adminCous = $this->couAdminFor($model, $coPersonId);

        // Next, walk through the list seeing if $subjectCoPersonId is a member. We do
        // one SQL query per COU, but an optimization that could be done is the query
        // WHERE cou_id IN (array_keys($adminCous)).

        foreach(array_keys($adminCous) as $couId) {
            // We accept statuses other than Active, since (eg) a COU Admin might want to view
            // the history of someone who is pending or expired.

            if($this->cachedPersonRoleCheck($subjectCoPersonId, $coId, $couId, false)) {
                // Match found, no need to continue
                return true;
            }
        }

        return false;
    }

    /**
   * Determine if a CO Person is in a COU.
   *
   * @since  COmanage Registry v0.7
   * @param  Integer CO Person ID
   * @param  Integer CO ID
   * @param  Integer COU ID
   * @return Boolean True if the person is in the COU, false otherwise
   */

    public function isCouPerson(Model $model, $coPersonId, $coId, $couId) {
        return $this->cachedPersonRoleCheck($coPersonId, $coId, $couId);
    }

    /**
   * Determine if a CO Person is can administer a CO Group.
   *
   * @since  COmanage Registry v0.8
   * @param  Integer CO Person ID of potential admin
   * @param  Integer CO Group ID
   * @return Boolean True if the CO Person can administer the CO Group, false otherwise
   * @throws InvalidArgumentException
   */

    public function isGroupManager(Model $model, $coPersonId, $coGroupId) {
        if(!$coPersonId || !$coGroupId) {
            return false;
        }

        // A person is a group manager if (1) they are an owner of the group or (2) they
        // are a CO admin for the CO of the group. Currently, we do not treat COU admins as
        // superusers for groups.

        if($this->cachedGroupCheck($coPersonId, "", "", $coGroupId, true)) {
            return true;
        }

        // Pull the CO Group CO ID, then see if $coPersonId is an admin

        $coId = $this->cachedCoIdLookupByCoGroup($coGroupId);

        return $this->isCoAdmin($model, $coPersonId, $coId);
    }

    /**
   * Determine if a CO Person is a participant (subject, recipient, or actor) for a CO Notification.
   *
   * @since  COmanage Registry v0.8.4
   * @param  Integer  $coNotificationId CO Notification ID
   * @param  Integer  $coPersonId       CO Person ID
   * @return Boolean  True if the CO Person ID is a participant in the notification, false otherwise
   * @throws InvalidArgumentException
   */

    public function isNotificationParticipant(Model $model, $coNotificationId,
                                              $coPersonId) {
        return $this->isNotificationRole($model, $coNotificationId, $coPersonId, 'participant');
    }

    /**
   * Determine if a CO Person is a recipient of a CO Notification.
   *
   * @since  COmanage Registry v0.8.4
   * @param  Integer  $coNotificationId CO Notification ID
   * @param  Integer  $coPersonId       CO Person ID
   * @return Boolean  True if the CO Person ID is a recipient of the notification, false otherwise
   * @throws InvalidArgumentException
   */

    public function isNotificationRecipient(Model $model, $coNotificationId,
                                            $coPersonId) {
        return $this->isNotificationRole($model, $coNotificationId, $coPersonId, 'recipient');
    }

    /**
   * Determine if a CO Person has the specified role for a CO Notification.
   *
   * @since  COmanage Registry v0.8.4
   * @param  Integer  $coNotificationId CO Notification ID
   * @param  Integer  $coPersonId       CO Person ID
   * @param  String   $role             'actor', 'participant', 'recipient', 'subject'
   * @return Boolean  True if the CO Person ID has the specified role for the notification, false otherwise
   * @throws InvalidArgumentException
   */

    protected function isNotificationRole(Model $model, $coNotificationId,
                                          $coPersonId,
                                          $role) {
        // Check the cache
        if(isset($this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role])) {
            return $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role];
        }

        $CoNotification = ClassRegistry::init('CoNotification');

        $not = $CoNotification->findById($coNotificationId);

        if(!empty($not['CoNotification'])) {
            if(($role == 'actor' || $role == 'participant')
               && $not['CoNotification']['actor_co_person_id'] == $coPersonId) {
                $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role] = true;

                return true;
            }

            if($role == 'recipient' || $role == 'participant') {
                if($not['CoNotification']['recipient_co_person_id'] == $coPersonId) {
                    $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role] = true;

                    return true;
                }

                // Check the recipient group
                if(!empty($not['CoNotification']['recipient_co_group_id'])) {
                    if($this->cachedGroupCheck($coPersonId, "", "", $not['CoNotification']['recipient_co_group_id'])) {
                        $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role] = true;

                        return true;
                    }
                }
            }

            if(($role == 'subject' || $role == 'participant')
               && $not['CoNotification']['subject_co_person_id'] == $coPersonId) {
                $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role] = true;

                return true;
            }
        } else {
            throw new InvalidArgumentException(_txt('er.notfound',
                                                    array(_txt('ct.co_notifications.1'), $coNotificationId)));
        }

        $this->cache['coperson'][$coPersonId]['co_notification'][$coNotificationId][$role] = false;

        return false;
    }

    /**
   * Determine if a CO Person is the sender of a CO Notification.
   *
   * @since  COmanage Registry v0.8.4
   * @param  Integer  $coNotificationId CO Notification ID
   * @param  Integer  $coPersonId       CO Person ID
   * @return Boolean  True if the CO Person ID is the sender of the notification, false otherwise
   * @throws InvalidArgumentException
   */

    public function isNotificationSender(Model $model, $coNotificationId,
                                         $coPersonId) {
        return $this->isNotificationRole($model, $coNotificationId, $coPersonId, 'actor');
    }
}
