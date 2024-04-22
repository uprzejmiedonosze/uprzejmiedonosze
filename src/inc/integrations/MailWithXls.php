<?php

class MailWithXls extends Mail {
    public function __construct() {
        $this->withXls = true;
    }
}