<?php
$config=array(
  'scz' => array(
#    'scope_suffix'  => '',
    'uid' => 'eppn',
    'attributes' => array(
      'eppn' => array('attribute'=>'eduPersonPrincipalName', 'type'=>'eppn'),

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
