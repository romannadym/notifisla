<?php

include ('../../../inc/includes.php');

Session::checkRight("user", READ);

$users = new PluginNotifislaUsers();
if (isset($_POST["add"]) && isset($_POST['config_id'])) {
    if($_POST['user_id'] != 0 )
    {
      $users->add([
            'config_id' => $_POST['config_id'],
            'user_id' => $_POST['user_id']
        ]);
    }
}
if (isset($_POST["delete"]) && isset($_POST['id'])) {
      $users->delete(['id' => $_POST['id']]);
}
Html::back();
