<?php

/**
 * -------------------------------------------------------------------------
 * notifisla plugin for GLPI
 * Copyright (C) 2024 by the notifisla Development Team.
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * --------------------------------------------------------------------------
 */

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_notifisla_install()
{
  global $DB;
  $version = plugin_version_notifisla();

  // Параметры автоматического действия
   $actionTitle = "queued plugin notification SLA";
   $actionClass = "PluginNotifislaTask"; // Имя класса
   $actionMethod = "addNotification"; // Метод класса

   // Регистрация действия
   CronTask::Register(
       $actionClass, // Объект (класс)
       $actionMethod, // Метод
       60, // Интервал (в минутах)
       [
           'comment'   => $actionTitle,
           'mode'      => 2, // Запуск по расписанию
           'parameter' => null,
       ]
   );
  //создать экземпляр миграции с версией
  $migration = new Migration($version['version']);
  //Create table only if it does not exists yet!
  if (!$DB->tableExists('glpi_plugin_notifisla_configs')) {
    // Запрос на создание таблицы с исправлениями
    $query = 'CREATE TABLE glpi_plugin_notifisla_configs (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, -- Беззнаковый идентификатор
      itilcategory_id INT UNSIGNED NOT NULL,   -- Беззнаковый идентификатор категории
      assigned_percent INT UNSIGNED NOT NULL DEFAULT 0, -- Процент как беззнаковый
      unresolved_percent INT UNSIGNED NOT NULL DEFAULT 0, -- Процент как беззнаковый
      date_mod TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    $DB->queryOrDie($query, $DB->error());
}

if (!$DB->tableExists('glpi_plugin_notifisla_users')) {
    // Запрос на создание таблицы с исправлениями
    $query = 'CREATE TABLE glpi_plugin_notifisla_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, -- Беззнаковый идентификатор
      config_id INT UNSIGNED NOT NULL,         -- Внешний ключ как беззнаковый
      user_id INT UNSIGNED NOT NULL,           -- Внешний ключ как беззнаковый
      date_mod TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    $DB->queryOrDie($query, $DB->error());
}


    if (Session::haveRight('itilcategory', READ)) {
      // Создаем экземпляр класса ItilCategory
      $itilCategory = new ItilCategory();

      // Получаем список категорий
      // Метод find возвращает массив категорий, удовлетворяющих условиям
      $categories = $itilCategory->find();
      foreach($categories AS $cid => $cdata)
      {
        $DB->insert('glpi_plugin_notifisla_configs',[
          'itilcategory_id'=>$cid
        ]);
      }
    }

    //execute the whole migration
    $migration->executeMigration();
    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_notifisla_uninstall()
{
    global $DB;
    CronTask::Unregister('PluginNotifislaTask','addNotification');
   $tables = [
        'configs',
        'users'
      ];
    foreach ($tables as $table) {
      $tablename = 'glpi_plugin_notifisla_' . $table;
      //Create table only if it does not exists yet!
      if ($DB->tableExists($tablename)) {
        $DB->queryOrDie(
          "DROP TABLE `$tablename`",
          $DB->error()
        );
      }
    }
    return true;
}
