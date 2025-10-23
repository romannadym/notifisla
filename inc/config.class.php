<?php

class PluginNotifislaConfig extends CommonDBTM {
  public $assigned_percent;
  public $unresolved_percent;
    static private $_instance = NULL;
    static $rightname = 'itilcategory';

    function getName($with_comment = 0) {
        return _n('Notification', 'Notification', $with_comment);
    }

    static function getInstance() {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
            if (!self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }

    /**
     * Отображение основной формы настройки
     */
    static function showConfigForm($item) {
        global $DB;
        if (!Session::haveRight("itilcategory", READ)) {
            echo "You do not have permission to view this profile.";
            return false;
        }
      //  die(PluginNotifislaTask::addNotification());
        $ITILCategory = new ITILCategory();

        // Проверяем, установлен ли ID в GET-запросе
        $ITILCategoryId = null;
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $ITILCategory->getFromDB($_GET['id']);
            $ITILCategoryId = $ITILCategory->getID();
        } else {
            echo "Profile ID is not set or invalid.";
            return false;
        }



        // *** Основная форма для настроек ***
        $config = new self();
        $config->showFormHeader(['no_header' => true]);
        $field = $config->find(['itilcategory_id' => $ITILCategoryId]);
        $data = current($field);
        if(!$data)
        {
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('itilcategory_id', ['value' => $ITILCategoryId]);
          echo "<h2 class=\"center\">Категория не связана с плагином \"notifisla\"</h2>";
          echo Html::submit(__('Связать'), [
              'name'  => 'sync_cat',
              'class' => 'center btn btn-primary mt-2'
          ]);
          Html::closeForm();
          return;
        }
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => $data['id']]);

        echo "<table class='tab_cadre_fixe mt-3'>";
        echo "<thead><tr class='tab_bg_1'>";
        echo "<th width='5%'>" . __('Settings') . "</th>";
        echo "<th width='10%'>" . __('Value', 'notifisla') . "</th>";
        echo "</tr></thead>";

        echo "<tbody>";
        // Поле для ввода значения "Не назначен исполнитель"
        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='no_assignee'>" . __(' Не назначен исполнитель по истечении X% от SLA ', 'notifisla') . ":</label></td>";
        echo "<td>";
        echo Html::input('no_assignee', [
            'type' => 'number',
            'value' => $data['assigned_percent'],
            'min' => 0,
            'max' => 100,
            'class' => 'form-control',
             'style' => 'width: 80px;' // Указываем желаемую ширину
        ]);
        echo "</td>";
        echo "</tr>";

        // Поле для ввода значения "Обращение не решено по истечении Y% от SLA"
        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='sla_percentage'>" . __('Обращение не решено по истечении Y% от SLA', 'notifisla') . ":</label></td>";
        echo "<td>";
        echo Html::input('sla_percentage', [
            'type' => 'number',
            'value' => $data['unresolved_percent'],
            'min' => 0,
            'max' => 100,
            'class' => 'form-control',
             'style' => 'width: 80px;' // Указываем желаемую ширину
        ]);
        echo "</td>";
        echo "</tr>";

        echo "</tbody>";

        echo "<tfoot>";
        echo "<tr class='center'><td colspan='2'>";
        echo Html::submit(__('Save'), [
            'name'  => 'update',
            'class' => 'btn btn-primary mt-2'
        ]);
        echo "</td></tr>";
        echo "</tfoot>";
        echo "</table>";

        Html::closeForm();

        // *** Форма для выбора пользователя ***
        echo "<form method='post' action='/plugins/notifisla/front/user.form.php'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('config_id', ['value' => $data['id']]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<thead><tr class='tab_bg_1'>";
        echo "<th colspan='2'>" . __('Receiver') . "</th>";
        echo "</tr></thead>";

        echo "<tbody>";
        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='user_id'>" . __('User') . ":</label></td>";
        echo "<td>";
        // Поле выбора пользователя
        $users = new PluginNotifislaUsers();
        $udata = $users->find(['config_id'=>$data['id']]);
        $excluded_users = [];
        foreach ($udata as $u) {
            $excluded_users[] = $u['user_id'];
        }
        User::dropdown([
            'name'       => 'user_id',
            'value'      => 0,
            'entity'     => $_SESSION['glpiactive_entity'],
            'right'      => 'all',
            'used' => $excluded_users //исключаем уже выбранных пользователей из выпадающего списка выбора пользователей
        ]);
        echo Html::submit(__('Add'), [
            'name' => 'add',
            'class' => 'btn btn-secondary ml-2 ms-5'
        ]);
        echo "</td>";
        echo "</tr>";
        echo "</tbody>";
        echo "</table>";
        Html::closeForm();
          // *** Форма списка выбранных пользователей ***

        echo "<table class='tab_cadre_fixe'>";
        echo "<thead><tr class='tab_bg_1'>";
        echo "<th >" .  _n('User', 'Users', 0) . "</th>";
        echo "<th >" .  __('Direct email') . "</th>";
        echo "<th >" .  _n('Action', 'Action', 0) . "</th>";
        echo "</tr></thead>";
        echo "<tbody>";
        foreach ($udata as $key => $value) {
          echo "<tr class='tab_bg_1'>";
          // Получаем данные пользователя
          $user = new User();
          $user->getFromDB($value['user_id']);
          $userId = $user->getID();

          // Получаем почту пользователя
          $email = $DB->fetchAssoc($DB->query("SELECT email FROM glpi_useremails WHERE users_id = $userId AND is_default = 1"));

          // Вывод данных пользователя
          echo "<td><a href='/front/user.form.php?id=$userId'>" . __($user->getField('realname') . ' ' . $user->getField('firstname'), 'notifisla') . ":</a></td>";
          echo "<td>" . __($email ? $email['email'] : 'Почта не найдена', 'notifisla') . "</td>";

          // Кнопка удаления
          echo "<td>";
          echo "<form method='post' action='/plugins/notifisla/front/user.form.php'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $value['id']]);
          echo Html::submit(__('Delete'), [
              'name'  => 'delete',
              'class' => 'btn btn-danger'
          ]);
            echo "</form>";
          echo "</td>";
          echo "</tr>";
      }
        echo "</tbody>";
        echo "</table>";
    }

    /**
     * Определение названия вкладки
     */
    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'ITILCategory') {
            return self::getName();
        }
        return '';
    }

    /**
     * Отображение содержимого вкладки
     */
    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'ITILCategory') {
            self::showConfigForm($item);
        }
        return true;
    }

}
