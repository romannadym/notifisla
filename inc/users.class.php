<?php

class PluginNotifislaUsers extends CommonDBTM {

    static private $_instance = NULL;
    static $rightname = 'users';

    static function getInstance() {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
            if (!self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }
}
