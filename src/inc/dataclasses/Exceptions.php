<?PHP

use \Exception as Exception;

class MissingParamException extends Exception {
    private string $param;
    public function __construct(string $param, string $msg=null, Exception $parent=null) {
        $this->param = $param;
        if (is_null($msg))
            $msg = "Brak wymaganego parametru '$param'";
        parent::__construct($msg, 400, $parent);
    }

    public function getParam() {
        return $this->param;
    }
}

class ForbiddenException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 401);
    }
}

class RejectWebhookException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 406);
    }
}

class MissingSMException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 307);
    }
}
