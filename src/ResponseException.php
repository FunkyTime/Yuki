<?php

namespace FunkyTime;


class ResponseException extends \Exception
{
    private string $response = '';

    public function __construct($message, $response)
    {
        $this->response = $response;
        parent::__construct($message);
    }

    public function getResponse(): string
    {
        return $this->response;
    }
}
