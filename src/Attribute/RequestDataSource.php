<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

enum RequestDataSource: string
{
    case Payload = 'payload';
    case Query = 'query';
    case Headers = 'headers';
    case Cookies = 'cookies';
    case Attributes = 'attributes';
    case Server = 'server';
    case Files = 'files';
}
