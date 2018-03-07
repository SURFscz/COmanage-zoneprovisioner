# comanage-sczprovisioner
This is a plugin for the [COmanage Registry](https://www.internet2.edu/products-services/trust-identity/comanage/) application as provided and maintained by the [Internet2](https://www.internet2.edu/) foundation.

This project has the following deployment goals:
- create a Provisioner Plugin for COmanage that provisions to a database using a specific output format


COmanage ZoneProvisioner Plugin
====================================
This plugin provisions people and services to an external database. The database configuration used is ```scz``` and must be defined in the datasource configuration. The plugin provisions only COPersons and the services attached to groups of which these COPersons are a member. Each service is identified using the ```entitlement_uri```.

Setup
=====
The provisioning plugin must be installed in the `local/Plugin` directory of the COmanage installation. Optionally, you can install it in the `app/AvailablePlugins` directory and link to it from the `local/Plugin` directory.

After installation, run the Cake database update script as per the COmanage instructions:
```
app/Console/cake database
```
You can now select the ZoneProvisioner plugin for your COmanage Registry groups.

Configuration
=============
The ZoneProvisioner allows configuration of output attributes using the Cake application configuration. Configuration uses the ```PhpReader``` Configuration reader to read an array of values from a file called ```scz.php``` in the application ```Config``` directory. An example configuration is provided in the ```Config``` directory of this Plugin.

The configuration resides inside a ```config``` variable and specifies basic server information, behaviour information and objectclass and attribute information:
* scope_suffix: a suffix to apply on scoped attributes like eduPersonScopedAffiliation and eduPersonUniqueId. This value supports template replacements to allow for situations where multiple COs are managed by a single COmanage installation. Currently, the following replacements are available: '{CO}' is replaced by the name of the CO associated with the provisioned entity.
* uid: the attribute to use as unique ID for a COPerson. This only names the attribute, but does not define it. The attribute used as uid has to be present in the list of attributes. If this is not the case, no entities will be provisioned due to a missing uid attribute. Normally, this uid is an attribute of the underlying OrgIdentity that allows matching a COPerson across different COs and is related to the IdP, e.g.: an eppn.
* export_uid_attribute: a boolean value indicating whether the attribute used as unique ID for a COPerson (see above) should be exported in the attribute list. You normally do not want that (so this is set to FALSE), because you want to keep IdP related attribute data (which this uid normally is) away from the attribute list you export to SPs (the content of which can usually be managed through COmanage by the user or administrator)
* attributes: a list of exportable attributes and their options

The attribute list is a keyed array, with the key representing the output attribute name and the value an array of options. For each attribute, the following options can be specified:
* name: the actual name of the attribute to replace. Usually, output name and attribute name are identical, but this allows rekeying the attribute
* type: the identifier, address or number type to take content from. This is usually something like 'home', 'official', 'fax', or 'network'. Values for type can be found in the relevant COmanage enumeration list for resp. identifiers, addresses and numbers. This type specification can be an array of types if several sources have to be checked.
* multiple: can be one of 'single', 'unique' and 'allow'. If not set, defaults to 'unique'. This determines what is done with attributes containing more than one value. 'single' will take the first value found. 'unique' will loop over all values and only retain unique content. 'allow' will leave duplicates.
* case: true or false (default). If set to true, unique checks for the multiple setting above are done case-sensitively.
* use_org: true or false (default). If set, takes attributes from the COOrgIdentity instead of the COPersonIdentity.

Supported attributes are:
* cn: from PrimaryName (single)
* givenName: from PrimaryName (single)
* sn: from PrimaryName (single)
* displayName: list of all defined names
* eduPersonNickName: list of all defined names
* eduPersonAffiliation: from COPersonRole affiliation (mapped)
* eduPersonScopedAffiliation: from COPersonRole affiliation, with scoping suffix (mapped)
* employeeType: from COPersonRole affiliation (raw)
* o: from COPersonRole
* ou: from COPersonRole
* title: from COPersonRole
* eduPersonOrcid: from Identifier, type fixed at 'orcid', can be forced to OrgIdentity
* eduPersonPrincipalName: from Identifier, can be forced to OrgIdentity
* eduPersonPrincipalNamePrior: from Identifier, can be forced to OrgIdentity
* eduPersonUniqueId: from Identifier, with scoping, can be forced to OrgIdentity
* employeeNumber: from Identifier, can be forced to OrgIdentity
* uid: from Identifier, can be forced to OrgIdentity
* sshPublicKey: generates an associated ssh public key line
* facsimileTelephoneNumber: from TelephoneNumber
* l: from Address
* mail: from EmailAddress
* mobile: from TelephoneNumber
* postalCode: from Address
* roomNumber: from Address
* st: from Address
* street: from Address
* telephoneNumber: from TelephoneNumber
* isMemberOf: list of group names. Each group name is preceded by the CO name associated with the provisioned entity, separated with a colon
* eduPersonEntitlement: list of Group/Service entitlements
* gecos: from PrimaryName
* gidnumber: from Identifier
* homeDirectory: from Identifier
* uidNumber: from Identifier

Example
=======
```
<?php
$config=array(
  'scz' => array(
#    'scope_suffix'  => '{CO}.example.com',
    'uid' => 'eppn',
    'attributes' => array(
      'eppn' => array('attribute'=>'eduPersonPrincipalName', 'type'=>'eppn', 'use_org'=>True),

      'cn' => array(),
      'givenName'=>array(),
      'sn'=>array(),
      'displayName'=>array('type'=>'preferred'),
      'eduPersonNickname'=>array('type'=>'official'),

      'eduPersonAffiliation' => array(),
      'employeeType' => array(),
      'o' => array(),
      'ou'=>array(),
      'title'=>array(),
      'eduPersonOrcid' => array(),
      'eduPersonPrincipalName' => array('type'=>'eppn'),
      'eduPersonPrincipalNamePrior' => array(),
      'employeeNumber' => array('type'=>'uid'),
      'mail'=>array(),
      'uid' => array('type'=>'official'),

      'sshPublicKey' => array(),
      'fax' => array('attribute'=>'facsimileTelephoneNumber', 'type'=>'fax'),
      'l' => array('type'=>'official'),
      'mail' => array(),
      'mobile' => array('type'=>'home'),
      'postalCode'=>array('type'=>'postal'),
      'roomNumber' => array('type'=>array('campus','official')),
      'st'=>array('type'=>'postal'),
      'street'=>array('type'=>'postal'),
      'telephoneNumber' => array('type'=>array('mobile','official')),

      'description' => array(),
      'isMemberOf' => array(),
      'eduPersonEntitlement' => array(),
      'gecos' => array(),
      'gidNumber' => array('type'=>'enterprise'),
      'homeDirectory' => array('type'=>'network'),
      'uidNumber'=>array('type'=>'uid')
    ),
  )
);
```
Tests
=====
This plugin comes without unit tests at the moment.

Disclaimer
==========
This plugin is provided AS-IS without any claims whatsoever to its functionality. The code is based partly on COmanage Registry code, distributed under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0).
