<?php
use Glpi\Toolbox\Sanitizer; // Импорт утилиты для безопасной обработки данных

class PluginNotifislaTask extends CommonDBTM
{
    static $tickets; // Статическое свойство для хранения списка тикетов

    /**
     * Основной метод, вызываемый по расписанию (например, через cron)
     */
    static function cronAddNotification()
    {
        // Генерация полей и выполнение логики уведомлений
        self::generateFields();
        return true; // Завершение задачи
    }

    /**
     * Генерация данных для отправки уведомлений
     */
    static function generateFields ()
    {
        global $DB;
        $notifisla = new PluginNotifislaConfig(); // Создание объекта конфигурации плагина

        // Поиск активных конфигураций с заданными условиями
        $notifisla = $notifisla->find(['OR' => [
            ['assigned_percent'=> ['>', 0]],  // Условие: процент SLA > 0 для незакрепленных задач
            ['unresolved_percent'=> ['>', 0]] // Условие: процент SLA > 0 для нерешенных задач
        ]]);

        foreach ($notifisla as $id => $value) {
            $users = new PluginNotifislaUsers(); // Создание объекта для работы с пользователями
            $users = $users->find(['config_id'=>$value['id']]); // Поиск пользователей для текущей конфигурации

            if (!empty($users)) { // Если пользователи найдены
                foreach ($users as $user) {
                    $user_id = $user['user_id'];
                    $user_n = new User();
                    $user_n->getFromDB($user['user_id']); // Загрузка данных пользователя из базы

                    // Получение email пользователя
                    $email = $DB->fetchAssoc($DB->query("SELECT email FROM glpi_useremails WHERE users_id = $user_id AND is_default = 1"));
                    $notifisla[$id]['users_email'][] = [
                        'name'  => $user_n->fields['realname'] . " " . $user_n->fields['firstname'], // Полное имя
                        'email' => $email['email'] // Email пользователя
                    ];
                }

                // Поиск тикетов, связанных с текущей конфигурацией
                self::$tickets = new Ticket();
                self::$tickets = self::$tickets->find([
                    'AND' => [
                        ['is_deleted' => 0], // Исключение удаленных тикетов
                        ['itilcategories_id' => $value['itilcategory_id']], // Категория тикета совпадает с конфигурацией
                        ['status' => ['<', 5]] // Статус тикета: незавершенный
                    ]
                ]);

                // Обработка каждого тикета
                foreach (self::$tickets as $ticket_id => $ticket) {
                    $sla = new SLA();
                    $sla = $sla->find(['id' => $ticket['slas_id_ttr']]); // Получение данных SLA для тикета
                    if (!empty($sla)) {
                        self::$tickets[$ticket_id]['slas_ttr'] = current($sla); // Сохранение SLA в данных тикета
                    }
                    $param['item_id'] = $ticket_id;
                    $param['itemtype'] = 'Ticket';

                    // Если SLA не подходит, тикет удаляется из массива
                    if (!self::getReportsSlaField($param)) {
                        unset(self::$tickets[$ticket_id]);
                    }
                }
                $notifisla[$id]['tickets'] = self::$tickets; // Сохранение списка тикетов для уведомления
            } else {
                unset($notifisla[$id]); // Удаление конфигурации, если нет подходящих пользователей
            }
        }

        // Если есть данные для уведомлений, вызываем метод отправки
        if (!empty($notifisla)) {
            self::sender($notifisla);
        }

        return true; // Завершение обработки
    }

    /**
     * Получение данных SLA и вычисление процента выполнения
     */
    static function getReportsSlaField($param)
    {
        global $DB;
        $item_id = $param['item_id']; // ID тикета
        $itemtype = $param['itemtype']; // Тип объекта (здесь это тикет)

        // Запрос к базе данных для получения времени SLA
        $filed = $DB->fetchAssoc($DB->query("SELECT slaremainsfield FROM glpi_plugin_reportssla_fields WHERE item_id = $item_id AND itemtype = '$itemtype'"));

        if (!$filed) {
            return false; // Если данных нет, SLA не учитывается
        }

        // Расчет процента выполнения SLA
        $hours = explode(":", $filed['slaremainsfield']); // Преобразование времени в часы
        $number_time = self::$tickets[$item_id]['slas_ttr']['number_time'];
        $hours = $hours[0] + ($hours[1] / 60);
        $percent = 100 - (($hours / $number_time * 100)); // Вычисление процента
        self::$tickets[$item_id]['percent'] = round($percent); // Сохранение процента в данных тикета

        return true;
    }

    /**
     * Отправка уведомлений пользователям
     */
    static function sender($params)
    {
        foreach ($params as $key => $value) {
            if (empty($value['tickets']) || empty($value['users_email'])) {
                continue; // Пропуск, если нет тикетов или email
            }

            $assigned_percent = $value['assigned_percent'];
            $unresolved_percent = $value['unresolved_percent'];

            // Отправка уведомлений для незакрепленных тикетов
            if ($assigned_percent) {
                self::assignedPercent(
                    options: $value['tickets'],
                    assigned_percent: $assigned_percent,
                    users_email: $value['users_email']
                );
            }

            // Отправка уведомлений для нерешенных тикетов
            if ($unresolved_percent) {
                self::unresolvedPercent(
                    options: $value['tickets'],
                    unresolved_percent: $unresolved_percent,
                    users_email: $value['users_email']
                );
            }
        }
    }

    /**
     * Логика уведомлений для assigned_percent
     */
    static function assignedPercent($options, $assigned_percent, $users_email)
    {
        global $CFG_GLPI;

        foreach ($options as $id => $option) {
            if ($option['percent'] > $assigned_percent && $option['status'] == 1) { // Условие для уведомления
                foreach ($users_email as $user) {
                  $QueuedNotification = new QueuedNotification();
                  $QueuedNotification = $QueuedNotification->find([
                    'event'     =>'not_assigned_percent',
                    'items_id'  =>$option['id'],
                    'recipient' =>$user['email'],
                    'mode'      => 'mailing',
                  ]);
                  if(!empty($QueuedNotification))
                  {
                    continue;
                  }
                  $link = $CFG_GLPI['url_base'].'/front/ticket.form.php?id='.$option['id'];
                  $p = [];
                  $p['_itemtype'] = "Ticket";
                  $p['_items_id'] = $option['id'];
                  $p['_notificationtemplates_id'] = 0;
                  $p['_entities_id'] = 0;
                  $p['from'] = $CFG_GLPI['admin_email'];
                  $p['fromname'] = $CFG_GLPI['admin_email_name'];
                  $p['event'] = "not_assigned_percent";
                  $p['subject'] = "Не назначен исполнитель";
                  $p['content_text'] = "";
                  $p['content_html'] = '<!DOCTYPE html>
                                          <html lang="ru">
                                          <head>
                                              <meta charset="UTF-8">
                                              <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                              <title>Уведомление SLA</title>
                                              <style>
                                                  body {
                                                      font-family: Arial, sans-serif;
                                                      background-color: #f9f9f9;
                                                      margin: 0;
                                                      padding: 0;
                                                  }
                                                  .container {
                                                      max-width: 600px;
                                                      margin: 20px auto;
                                                      background-color: #ffffff;
                                                      border: 1px solid #dddddd;
                                                      border-radius: 5px;
                                                      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                                                      padding: 20px;
                                                  }
                                                  .header {
                                                      text-align: center;
                                                      background-color: #007bff;
                                                      color: #ffffff;
                                                      padding: 15px 0;
                                                      border-radius: 5px 5px 0 0;
                                                  }
                                                  .header h1 {
                                                      margin: 0;
                                                      font-size: 20px;
                                                  }
                                                  .content {
                                                      margin: 20px 0;
                                                      font-size: 16px;
                                                      line-height: 1.5;
                                                  }
                                                  .content strong {
                                                      color: #333333;
                                                  }
                                                  .footer {
                                                      text-align: center;
                                                      font-size: 14px;
                                                      color: #777777;
                                                      margin-top: 20px;
                                                  }
                                              </style>
                                          </head>
                                          <body>
                                              <div class="container">
                                                  <div class="header">
                                                      <h1>Уведомление SLA</h1>
                                                  </div>
                                                  <div class="content">
                                                      <p>Добрый день!</p>
                                                      <p>Информирую вас о том, что по <strong>обращению №'.$option['id'].'</strong> истекло <strong>'.$option['percent'].'%</strong> от SLA, исполнитель до настоящего момента не назначен.</p>
                                                      <p><a href="'.$link.'" target="_blank">'.$link.'</a></p>
                                                      <p>С уважением,</p>
                                                      <p><strong>Служба технической поддержки</strong></p>
                                                  </div>
                                                  <div class="footer">
                                                      Это письмо сформировано автоматически, отвечать на него не нужно.
                                                  </div>
                                              </div>
                                          </body>
                                          </html>';
                  $p['to'] = $user['email'];
                  $p['toname'] = $user['name'];
                  $p['replyto'] = $CFG_GLPI['admin_email'];
                  $p['replytoname'] = $CFG_GLPI['admin_email_name'];
                 $NotificationMailing = new NotificationMailing();
                 $NotificationMailing->sendNotification($p);
                  //echo json_encode($CFG_GLPI['url_base'],JSON_UNESCAPED_UNICODE)."<br><br>";die(0);
                }
            }
        }
    }

    /**
     * Логика уведомлений для unresolved_percent
     */
    static function unresolvedPercent($options, $unresolved_percent, $users_email)
    {
        global $CFG_GLPI;

        foreach ($options as $id => $option) {
            if ($option['percent'] > $unresolved_percent && $option['status'] != 5) { // Условие для уведомления
                foreach ($users_email as $user) {
                  $QueuedNotification = new QueuedNotification();
                  $QueuedNotification = $QueuedNotification->find([
                    'event'     =>'not_unresolved_percent',
                    'items_id'  =>$option['id'],
                    'recipient' =>$user['email'],
                    'mode'      => 'mailing',
                  ]);
                  if(!empty($QueuedNotification))
                  {
                    continue;
                  }
                  $link = $CFG_GLPI['url_base'].'/front/ticket.form.php?id='.$option['id'];
                  $p = [];
                  $p['_itemtype'] = "Ticket";
                  $p['_items_id'] = $option['id'];
                  $p['_notificationtemplates_id'] = 0;
                  $p['_entities_id'] = 0;
                  $p['from'] = $CFG_GLPI['admin_email'];
                  $p['fromname'] = $CFG_GLPI['admin_email_name'];
                  $p['event'] = "not_unresolved_percent";
                  $p['subject'] = "Обращение не решено";
                  $p['content_text'] = "";
                  $p['content_html'] = '<!DOCTYPE html>
                                          <html lang="ru">
                                          <head>
                                              <meta charset="UTF-8">
                                              <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                              <title>Уведомление SLA</title>
                                              <style>
                                                  body {
                                                      font-family: Arial, sans-serif;
                                                      background-color: #f9f9f9;
                                                      margin: 0;
                                                      padding: 0;
                                                  }
                                                  .container {
                                                      max-width: 600px;
                                                      margin: 20px auto;
                                                      background-color: #ffffff;
                                                      border: 1px solid #dddddd;
                                                      border-radius: 5px;
                                                      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                                                      padding: 20px;
                                                  }
                                                  .header {
                                                      text-align: center;
                                                      background-color: #007bff;
                                                      color: #ffffff;
                                                      padding: 15px 0;
                                                      border-radius: 5px 5px 0 0;
                                                  }
                                                  .header h1 {
                                                      margin: 0;
                                                      font-size: 20px;
                                                  }
                                                  .content {
                                                      margin: 20px 0;
                                                      font-size: 16px;
                                                      line-height: 1.5;
                                                  }
                                                  .content strong {
                                                      color: #333333;
                                                  }
                                                  .footer {
                                                      text-align: center;
                                                      font-size: 14px;
                                                      color: #777777;
                                                      margin-top: 20px;
                                                  }
                                              </style>
                                          </head>
                                          <body>
                                              <div class="container">
                                                  <div class="header">
                                                      <h1>Уведомление SLA</h1>
                                                  </div>
                                                  <div class="content">
                                                      <p>Добрый день!</p>
                                                      <p>Информирую вас о том, что по <strong>обращению №'.$option['id'].'</strong> истекло <strong>'.$option['percent'].'%</strong> от SLA, обращение до настоящего момента не решено.</p>
                                                      <p><a href="'.$link.'" target="_blank">'.$link.'</a></p>
                                                      <p>С уважением,</p>
                                                      <p><strong>Служба технической поддержки</strong></p>
                                                  </div>
                                                  <div class="footer">
                                                      Это письмо сформировано автоматически, отвечать на него не нужно.
                                                  </div>
                                              </div>
                                          </body>
                                          </html>';
                  $p['to'] = $user['email'];
                  $p['toname'] = $user['name'];
                  $p['replyto'] = $CFG_GLPI['admin_email'];
                  $p['replytoname'] = $CFG_GLPI['admin_email_name'];
                 $NotificationMailing = new NotificationMailing();
                 $NotificationMailing->sendNotification($p);
                  //echo json_encode($CFG_GLPI['url_base'],JSON_UNESCAPED_UNICODE)."<br><br>";die(0);
                }
            }
        }
    }
}
