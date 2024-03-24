<?PHP

class MissingParamException extends Exception {
    private ?string $param = null;
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
