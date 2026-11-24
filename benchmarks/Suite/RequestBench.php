<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\Container;
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
        $this->container = Fixtures::container(Fixtures::attributeSections($params['uncached'], $withProviders));
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
    }

    public function verify(array $params): void
    {
        $valid = match ($params['method']) {
            'plain', 'callback' => $this->result === $this->data,
            'dto', 'dtoCallback', 'dtoCastValidated' => $this->result instanceof MappedDto && $this->result->value === 'mapped',
            'extract' => $this->result === [
                $this->request, $this->request->getUri(), 'q', 'mapped', 'trace', 'c', 'r', 's',
                $this->request->getUploadedFiles()['upload'],
            ],
            'allSources' => $this->result === [
                ['query' => 'q'], $this->data, ['X-Token' => 'trace'], ['cookie' => 'c'], ['route' => 'r'],
                ['server' => 's'], $this->request->getUploadedFiles(), ['query' => 'q', 'cookie' => 'c'],
            ],
        };
        Fixtures::check($valid, 'Incorrect request mapping or extraction result.');
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
