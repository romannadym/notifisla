<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include ('../../../inc/includes.php');

Session::checkRight("itilcategory", READ);

$config = new PluginNotifislaConfig();

if (isset($_POST["update"])) {
   // Получаем нужную запись из базы
   $config->getFromDB($_POST['id']);

   // Обновляем значения
   $params['assigned_percent'] = $_POST['no_assignee'];
   $params['unresolved_percent'] = $_POST['sla_percentage'];
   $params['id'] = $_POST['id'];
   // Сохраняем изменения
   $config->update($params);
 //die(var_dump($config));
}

Html::back();
