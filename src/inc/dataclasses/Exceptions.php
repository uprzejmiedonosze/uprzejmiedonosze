<?PHP

use \Exception as Exception;

class MissingParamException extends Exception {
    private string $param;
    public function __construct(string $param, ?string $msg=null, ?Exception $parent=null) {
        $this->param = $param;
        if (is_null($msg))
            $msg = "Brak wymaganego parametru '$param'";
        parent::__construct($msg, 400, $parent);
    }

    public function getParam() {
        return $this->param;
    }
}

/** Invalid report field value (HTTP 422); `field` names the offending form field. */
class ValidationException extends Exception {
    private string $field;
    public function __construct(string $field, string $msg) {
        $this->field = $field;
        parent::__construct($msg, 422);
    }

    public function getField(): string {
        return $this->field;
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

class RejectSilentlyWebhookException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 200);
    }
}

class MissingSMException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 307);
    }
}

class NotSendableException extends Exception {
    public function __construct(string $msg) {
        parent::__construct($msg, 409);
    }
}
