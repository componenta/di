<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ServerRequestInterface;

#[Bench\Iterations(7)]
#[Bench\Revs(1000)]
final class RequestBench
{
    private Container $container;
    private ServerRequestInterface $request;
    private array $provided;
    private array $target;
    private array $data;
    private mixed $result;
    private ?DtoValidator $validator = null;
    private int $validationCount = 0;
    private array $requests;
    private int $requestIndex = 0;

    public function provideRequests(): iterable
    {
        foreach ([false, true] as $uncached) {
            $suffix = $uncached ? '-uncached-plan' : '-cached-plan';
            foreach (['plain', 'callback'] as $method) {
                foreach ([1, 16, 256, 4096] as $size) {
                    yield $method . '-' . $size . $suffix => [
                        'method' => $method,
                        'size' => $size,
                        'uncached' => $uncached,
                    ];
                }
            }
            foreach (['dto', 'dtoCallback', 'dtoCastValidated', 'extract', 'allSources'] as $method) {
                yield $method . $suffix => ['method' => $method, 'size' => 1, 'uncached' => $uncached];
            }
        }
    }

    public function prepare(array $params): void
    {
        $withProviders = $params['method'] === 'dtoCastValidated';
        $sections = Fixtures::attributeSections($params['uncached'], $withProviders);
        $this->validator = $sections[ConfigKey::SERVICES][ValidationProviderInterface::class] ?? null;
        $this->validationCount = 0;
        $this->container = Fixtures::container($sections);
        $this->data = ['value' => $withProviders ? ' mapped ' : 'mapped'];
        for ($i = 1; $i < $params['size']; ++$i) {
            $this->data['field-' . $i] = $i;
        }
        $upload = new UploadedFile(Stream::create('fixture'), 7, UPLOAD_ERR_OK);
        $this->request = (new ServerRequest('POST', '/', ['X-Token' => 'trace'], '', '1.1', ['server' => 's']))
            ->withParsedBody($this->data)
            ->withQueryParams(['query' => 'q'])
            ->withCookieParams(['cookie' => 'c'])
            ->withAttribute('route', 'r')
            ->withUploadedFiles(['upload' => $upload]);
        $this->provided = [ServerRequestInterface::class => $this->request];
        $this->target = [RequestTargets::class, $params['method']];
    }

    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        $this->result = $this->container->call($this->target, $this->provided);
        $this->verify($params);

        // Warm metadata with one request, then measure a different payload.
        $this->data['value'] = $params['method'] === 'dtoCastValidated' ? ' next ' : 'next';
        $this->request = $this->request->withParsedBody($this->data);
        $this->provided = [ServerRequestInterface::class => $this->request];
        $this->validationCount = $this->validator?->calls ?? 0;
    }

    public function verify(array $params): void
    {
        $value = $this->data['value'];
        $valid = match ($params['method']) {
            'plain' => $this->result === $this->data,
            'callback' => $this->result === array_replace($this->data, ['value' => $value . '-callback']),
            'dto' => $this->result instanceof MappedDto && $this->result->value === $value,
            'dtoCastValidated' => $this->result instanceof MappedDto && $this->result->value === trim($value),
            'dtoCallback' => $this->result instanceof MappedDto && $this->result->value === $value . '-callback',
            'extract' => $this->result === [
                $this->request, $this->request->getUri(), 'q', $value, 'trace', 'c', 'r', 's',
                $this->request->getUploadedFiles()['upload'],
            ],
            'allSources' => $this->result === [
                ['query' => 'q'], $this->data, ['X-Token' => 'trace'], ['cookie' => 'c'], ['route' => 'r'],
                ['server' => 's'], $this->request->getUploadedFiles(), ['query' => 'q', 'cookie' => 'c'],
            ],
        };
        Fixtures::check($valid, 'Incorrect request mapping or extraction result.');
        if ($this->validator !== null) {
            Fixtures::check(
                $this->validator->calls > $this->validationCount && $this->validator->lastInput === $value,
                'Validation did not receive the current raw request data.',
            );
        }
    }

    public function provideAlternatingRequests(): iterable
    {
        foreach ([false, true] as $uncached) {
            $suffix = $uncached ? '-uncached-plan' : '-cached-plan';
            foreach (['dto', 'dtoCallback', 'dtoCastValidated', 'extract'] as $method) {
                yield $method . $suffix => ['method' => $method, 'size' => 1, 'uncached' => $uncached];
            }
        }
    }

    public function prepareAlternating(array $params): void
    {
        $this->prepare($params);
        $first = $this->provided;
        $this->result = $this->container->call($this->target, $first);
        $this->verify($params);
        $this->data['value'] = $params['method'] === 'dtoCastValidated' ? ' next ' : 'next';
        $this->request = $this->request->withParsedBody($this->data);
        $second = [ServerRequestInterface::class => $this->request];
        $this->validationCount = $this->validator?->calls ?? 0;
        $this->result = $this->container->call($this->target, $second);
        $this->verify($params);
        $this->requests = [$first, $second];
        $this->requestIndex = 0;
        $this->validationCount = $this->validator?->calls ?? 0;
    }

    public function verifyAlternating(array $params): void
    {
        $this->request = $this->requests[1 - $this->requestIndex][ServerRequestInterface::class];
        $this->data = $this->request->getParsedBody();
        $this->verify($params);
    }

    #[Bench\ParamProviders('provideAlternatingRequests')]
    #[Bench\BeforeMethods('prepareAlternating')]
    #[Bench\AfterMethods('verifyAlternating')]
    #[Bench\Revs(200)]
    #[Bench\Warmup(20)]
    public function benchAlternatingRequests(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->requests[$this->requestIndex]);
        $this->requestIndex = 1 - $this->requestIndex;
    }

    #[Bench\ParamProviders('provideRequests')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstRequest(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->provided);
    }

    #[Bench\ParamProviders('provideRequests')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(200)]
    #[Bench\Warmup(20)]
    public function benchRepeatedRequest(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->provided);
    }
}
