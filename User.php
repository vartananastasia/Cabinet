<?php

namespace Cabinet;


/**
 * Class User
 * @package Cabinet
 */
class User extends \App\Model\User {

    /**
     * @var array
     */
    private $user;

    /**
     * @var array
     */
    private $watches;

    /**
     * @var array
     */
    private $user_statuses;

    /**
     * @var array
     */
    private $watch_statuses;


    const DELTA = 300;
    const CODE_ACCESS = 3;
    const CODE_LIMIT = 3;
    const CODE_LIMIT_TIME = 86400;


    /**
     * User constructor.
     * @param $number
     */
    public function __construct($number)
    {
        $phone = preg_replace("/[^0-9]/", '', $number);
        if (strlen($phone) == 11) {
            $user = parent::getUserByPhone($phone);
            $this->user = $user;
        }else
            $this->user = [];

        if(self::UserExists()) {
            $watchUser = new \App\Model\WatchUser();
            $watch = new \App\Model\Watch();
            $user_watches = $watchUser->getList(array("filter" => array("user_id" => self::getUserId())));
            foreach ($user_watches as $key => &$watchPare) {
                $this->watches[$key]['watch_info'] = $watch->getRow(array("filter" => array("id" => $watchPare['watch_id'])));
                $this->watches[$key]['watch_info']['child_age'] = $watchUser->getAge($watchPare['watch_info']['child_birthday']);
            }
            $this->user_statuses = $watchUser->getStatuses();
            $this->watch_statuses = $watch->getStatuses();
        }else{
            $this->user_statuses = $this->watch_statuses = $this->watches = [];
        }
    }


    /**
     * Checks if user exists
     * @return bool
     */
    public function UserExists(){
        if ($this->user)
            return true;
        else
            return false;
    }


    /**
     * To get user ID
     * @return integer
     */
    public function getUserId(){
        return $this->user["ID"];
    }


    /**
     * To get user phone
     * @return integer
     */
    public function getUserPhone(){
        return $this->user["PERSONAL_PHONE"];
    }


    /**
     * To write code in logs
     * @return array
     */
    private function CodeWrite(){
        $code = rand(1000,9999);
        $user_code = \Lexand\Hiload::GetHLEntityClass(\Lexand\Helper::CABINET_USER_CODE);
        $created = $user_code::add(
            [
                'UF_PHONE' => self::getUserPhone(),
                'UF_CODE' => $code,
                'UF_DATE' => date("d.m.Y H:i:s")
            ]
        );
        return ["success" => $created->isSuccess(), "code" => $code];
    }


    /**
     * Generating, writing and sending code to user
     * @return bool
     */
    public function CodeGenerate(){
        $code_write = self::CodeWrite();
        $res = false;
        if($code_write["success"]) {
            $sms_aero_user = \Lexand\Hiload::GetHLItemsByID(\Lexand\Helper::SMS_AERO_USER)[0];
            $send = new \SMS\Client($sms_aero_user['UF_LOGIN'], $sms_aero_user['UF_PASSWORD']);
            $sms = new \SMS\SMS("Код для входа kidsradar: {$code_write["code"]}", $this->getUserPhone());

            $settings = include($_SERVER["DOCUMENT_ROOT"] . "/bitrix/.settings.php");

            try {
                if (!$settings['exception_handling']['value']['debug'])
                    $send->send_sms($sms, \SMS\Client::TYPE_5);
                $res = true;
            } catch (\SMS\SMS_API_Exeption $e_api) {
                $sms_aero = \Lexand\Hiload::GetHLEntityClass(\Lexand\Helper::SMS_AERO);
                $sms_aero::add(
                    [
                        'UF_NUMBER' => $_POST["user_phone"],
                        'UF_ERROR' => $e_api->__toString(),
                        'UF_DATE' => date("d.m.Y H:i:s")
                    ]
                );
            } catch (\SMS\SMS_Exeption $e) {
                $sms_aero = \Lexand\Hiload::GetHLEntityClass(\Lexand\Helper::SMS_AERO);
                $sms_aero::add(
                    [
                        'UF_NUMBER' => $_POST["user_phone"],
                        'UF_ERROR' => $e->__toString(),
                        'UF_DATE' => date("d.m.Y H:i:s")
                    ]
                );
            }
        }
        return $res;
    }


    /**
     * Checking code to identify user
     * @param int $code
     * @return bool
     */
    public function CodeCheck($code = 0){
        $res = false;
        if ($code){
            $codes = \Lexand\Hiload::GetHLEntityClass(\Lexand\Helper::CABINET_USER_CODE);
            $user_codes = $codes::getList(array(
                "select" => array("*"),
                "order" => array("UF_DATE" => "DESC"),
                "filter" => array("UF_PHONE"=>self::getUserPhone())
            ));
            foreach ($user_codes as $key => $user_code){
                if ($user_code["UF_CODE"] == $code && time() - $user_code["UF_DATE"]->getTimestamp() < self::DELTA) {
                    $res = true;
                    break;
                }
                if ($key>self::CODE_ACCESS) {
                    break;
                }
            }
        }
        return $res;
    }


    /**
     * Only CODE_LIMIT sms in CODE_LIMIT_TIME without checking
     * @return bool
     */
    public function CodeLimitReached(){
        $time_start = time() - self::CODE_LIMIT_TIME;
        $res = false;
        $limit = self::CODE_LIMIT + 1;
        $codes = \Lexand\Hiload::GetHLEntityClass(\Lexand\Helper::CABINET_USER_CODE);
        $user_codes = $codes::getList(array(
            "select" => array("*"),
            "order" => array("UF_DATE" => "DESC"),
            "filter" => array("UF_PHONE"=>self::getUserPhone()),
            "limit" => $limit
        ));
        $count = 0;
        foreach ($user_codes as $code) {
            if($code["UF_DATE"]->getTimestamp() > $time_start)
                $count++;
            if ($count == self::CODE_LIMIT) {
                $res = true;
                break;
            }
        }
        return $res;
    }
}