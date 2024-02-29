<?php

declare(strict_types=1);

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @param string $field
     * @param array|null $template_data
     * @param int $id
     * @return string
     */
    public static function __(string $field, ?array $template_data, int $id): string
    {
        //какой-то код для обработки шаблона
        $result = "";
        return $result;
    }
    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');

        //обязательные поля
        $expected_fields = [
            'complaintId', 'complaintNumber', 'creatorId',
            'expertId', 'consumptionId', 'consumptionNumber',
            'agreementNumber', 'date', 'resellerId', 'notificationType',
            'clientId'
        ];

        foreach ($expected_fields AS $field) {
            if (empty($data[$field])) {
                throw new Exception("Expected field [$field] is empty!", 500);
            }
        }

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        $reseller_id = (int)$data['resellerId'];
        $notification_type = (int)$data['notificationType'];
        $client_id = (int)$data['clientId'];
        $creator_id = (int)$data['creatorId'];
        $expert_id = (int)$data['expertId'];

        $reseller = Seller::getById($reseller_id);
        if (is_null($reseller)) {
            throw new Exception('Seller not found!', 400);
        }

        $client = Contractor::getById($client_id);
        if (is_null($client) || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $reseller_id) {
            throw new Exception('Client not found!', 400);
        }

        $creator = Employee::getById($creator_id);
        if (is_null($creator)) {
            throw new Exception('Creator not found!', 400);
        }

        $expert = Employee::getById($expert_id);
        if (is_null($expert)) {
            throw new Exception('Expert not found!', 400);
        }

        $client_full_name = $client->getFullName();
        if (empty($client_full_name)) {
            $client_full_name = $client->name;
        }

        $creator_full_name = $creator->getFullName();
        if (empty($creator_full_name)) {
            $creator_full_name = $creator->name;
        }

        $expert_full_name = $expert->getFullName();
        if (empty($expert_full_name)) {
            $expert_full_name = $expert->name;
        }

        $differences = '';
        if ($notification_type === self::TYPE_NEW) {
            $differences = self::__('NewPositionAdded', null, $reseller_id);
        } elseif ($notification_type === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = self::__('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to']),
            ], $reseller_id);
        }

        $template_data = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => $creator_id,
            'CREATOR_NAME'       => $creator_full_name,
            'EXPERT_ID'          => $expert_id,
            'EXPERT_NAME'        => $expert_full_name,
            'CLIENT_ID'          => $client_id,
            'CLIENT_NAME'        => $client_full_name,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($template_data as $key => $temp_data) {
            if (empty($temp_data)) {
                throw new Exception("Template Data ($key) is empty!", 500);
            }
        }

        $email_from = getResellerEmailFrom();

        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($reseller_id, 'tsGoodsReturn');
        if (!empty($email_from) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $email_from,
                           'emailTo'   => $email,
                           'subject'   => self::__('complaintEmployeeEmailSubject', $template_data, $reseller_id),
                           'message'   => self::__('complaintEmployeeEmailBody', $template_data, $reseller_id),
                    ],
                ], $reseller_id, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notification_type === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($email_from) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $email_from,
                           'emailTo'   => $client->email,
                           'subject'   => self::__('complaintClientEmailSubject', $template_data, $reseller_id),
                           'message'   => self::__('complaintClientEmailBody', $template_data, $reseller_id),
                    ],
                ], $reseller_id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $error = false;
                $res = NotificationManager::send($reseller_id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $template_data, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
