<?php

App::uses('Model', 'Model');
App::uses('Controller', 'Controller');
App::uses('CakeEmail', 'Network/Email');

class CoLdapFixedProvisionerTargetTest extends CakeTestCase {

  public $useDbConfig=false;

  public $fixtures = array(
	'plugin.ldapFixedProvisioner.coprovisioningtarget',
	'plugin.ldapFixedProvisioner.coldapfixedprovisionertarget',
	'plugin.ldapFixedProvisioner.coldapfixedprovisionerdn',
#	"plugin.ldapFixedProvisioner.co",
#	"plugin.ldapFixedProvisioner.cogroup",
#	"plugin.ldapFixedProvisioner.consfdemographic",
#	"plugin.ldapFixedProvisioner.coinvite",
#	"plugin.ldapFixedProvisioner.conotification",
#	"plugin.ldapFixedProvisioner.orgidentity",
#	"plugin.ldapFixedProvisioner.coorgidentitylink",
#	"plugin.ldapFixedProvisioner.copersonrole",
#	"plugin.ldapFixedProvisioner.copetition",
#	"plugin.ldapFixedProvisioner.copetitionhistoryrecord",
#	"plugin.ldapFixedProvisioner.cotandcagreement",
#	"plugin.ldapFixedProvisioner.emailaddress",
#	"plugin.ldapFixedProvisioner.historyrecord",
#	"plugin.ldapFixedProvisioner.coprovisioningexport",
#	"plugin.ldapFixedProvisioner.sshkey",
#	"plugin.ldapFixedProvisioner.cou",
#	"plugin.ldapFixedProvisioner.coenrollmentflow",
#	"plugin.ldapFixedProvisioner.coexpirationpolicy",
#	"plugin.ldapFixedProvisioner.cosetting",
#	"plugin.ldapFixedProvisioner.coservice",
#	"plugin.ldapFixedProvisioner.name",
#	"plugin.ldapFixedProvisioner.coperson",
#	"plugin.ldapFixedProvisioner.identifier",
#	"plugin.ldapFixedProvisioner.cogroupmember",
#	"plugin.ldapFixedProvisioner.telephone",
#	"plugin.ldapFixedProvisioner.address",
  );

  public $CEPT;

  public function startTest($method) {
	$this->CLPT = ClassRegistry::init('LdapFixedProvisioner.CoLdapFixedProvisionerTarget');
    $this->CLD = ClassRegistry::init('LdapFixedProvisioner.CoLdapFixedProvisionerDn');
    $this->CPT = ClassRegistry::init('CoProvisioningTarget');
    $this->CP = ClassRegistry::init('CoPerson');
    $this->CG = ClassRegistry::init('CoGroup');
    _bootstrap_plugin_txt(); // this is normally done in the Controller, but we do not have a controller
  }

  public function endTest($method) {
	unset($this->CLPT);
	unset($this->CLD);
	unset($this->CPT);
	unset($this->CP);
	unset($this->CG);
  }

  protected static function getMethod($obj, $name) {
    $class = new ReflectionClass(get_class($obj));
    $method = $class->getMethod($name);
    $method->setAccessible(true);
    return $method;
  }

  public function testVerifyLdapServer() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      TRUE, // ldap_search
      array(1,2),// ldap_get_entries
      TRUE, // ldap_unbind
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      TRUE, // ldap_search
      array(1,2),// ldap_get_entries
      TRUE,// ldap_unbind
    );
    $content='[["ldap_connect",["ldap:\/\/\/"]],'.
        '["ldap_set_option",[17,3]],'.
        '["ldap_bind",["cn=bind,dc=example,dc=com","password"]],'.
        '["ldap_search",["ou=People,dc=example,dc=com","(objectclass=*)",["dn"]]],'.
        '["ldap_get_entries",[true]],'.
        '["ldap_unbind",[]],'.
        '["ldap_connect",["ldap:\/\/\/"]],'.
        '["ldap_set_option",[17,3]],'.
        '["ldap_bind",["cn=bind,dc=example,dc=com","password"]],'.
        '["ldap_search",[" ou=Groups,dc=example,dc=com","(objectclass=*)",["dn"]]],'.
        '["ldap_get_entries",[true]],'.
        '["ldap_unbind",[]]]';
    $return_value=$method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
    $this->assertEquals($content,json_encode(LdapServiceBehavior::$content),"verify of ldap calls");
  }

  public function testVerifyLdapServerConnect() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      FALSE, // ldap_connect
    );
    $this->expectException("RuntimeException");
    $return_value=$method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
  }

  public function testVerifyLdapServerBind() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      FALSE, // ldap_bind
    );
    $this->expectException("RuntimeException");
    $method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
  }

  public function testVerifyLdapServerSearch() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      FALSE, // ldap_search
    );
    $this->expectException("RuntimeException");
    $method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
  }

  public function testVerifyLdapServerEntries1() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      TRUE, // ldap_search
      array(),// ldap_get_entries
    );
    $this->expectException("RuntimeException");
    $method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
  }

  public function testVerifyLdapServerEntries2() {
    $method = $this->getMethod($this->CLPT,"verifyLdapServer");
    LdapServiceBehavior::$content=array();
    LdapServiceBehavior::$expected=array(
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      TRUE, // ldap_search
      array(1,2),// ldap_get_entries
      TRUE, // ldap_unbind
      TRUE, // ldap_connect
      TRUE, // ldap_set_options
      TRUE, // ldap_bind
      TRUE, // ldap_search
      array(),// ldap_get_entries
    );
    $this->expectException("RuntimeException");
    $method->invokeArgs($this->CLPT, array("ldap:///", "cn=bind,dc=example,dc=com", "password", "ou=People,dc=example,dc=com"," ou=Groups,dc=example,dc=com"));
  }

}

App::uses('ModelBehavior', 'Model');
class LdapServiceBehavior extends ModelBehavior {
    public static $content=array();
    public static $expected=array();

    public function ldap_connect(Model $Model, $host) {
      LdapServiceBehavior::$content[]=array("ldap_connect",array($host));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_set_option(Model $Model, $opt, $val) {
      LdapServiceBehavior::$content[]=array("ldap_set_option",array($opt,$val));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_bind(Model $Model, $binddn,$password) {
      LdapServiceBehavior::$content[]=array("ldap_bind",array($binddn,$password));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_unbind(Model $Model) {
      LdapServiceBehavior::$content[]=array("ldap_unbind",array());
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_search(Model $Model, $baseDn, $filter, $attributes) {
      LdapServiceBehavior::$content[]=array("ldap_search",array($baseDn, $filter,$attributes));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_get_entries(Model $Model, $s) {
      LdapServiceBehavior::$content[]=array("ldap_get_entries",array($s));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_error(Model $Model) {
      LdapServiceBehavior::$content[]=array("ldap_error",array());
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_errno(Model $Model) {
      LdapServiceBehavior::$content[]=array("ldap_errno",array());
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_add(Model $Model, $dn, $attributes) {
      LdapServiceBehavior::$content[]=array("ldap_add",array($dn, $attributes));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_rename(Model $Model, $olddn, $newdn) {
      LdapServiceBehavior::$content[]=array("ldap_rename",array($olddn,$newdn));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_mod_replace(Model $Model, $dn, $attributes) {
      LdapServiceBehavior::$content[]=array("ldap_mod_replace",array($dn, $attributes));
      return array_shift(LdapServiceBehavior::$expected);
    }

    public function ldap_delete(Model $Model, $dn) {
      LdapServiceBehavior::$content[]=array("ldap_delete",array($dn));
      return array_shift(LdapServiceBehavior::$expected);
    }
}
