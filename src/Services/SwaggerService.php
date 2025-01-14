<?php

namespace RonasIT\Support\AutoDoc\Services;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Minime\Annotations\Interfaces\AnnotationsBagInterface;
use Minime\Annotations\Reader as AnnotationReader;
use Minime\Annotations\Parser;
use Minime\Annotations\Cache\ArrayCache;
use ReflectionException;
use RonasIT\Support\AutoDoc\Interfaces\DataCollectorInterface;
use RonasIT\Support\AutoDoc\Traits\GetDependenciesTrait;
use RonasIT\Support\AutoDoc\Exceptions\WrongSecurityConfigException;
use RonasIT\Support\AutoDoc\Exceptions\DataCollectorClassNotFoundException;
use RonasIT\Support\AutoDoc\DataCollectors\LocalDataCollector;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Testing\File;

/**
 * @property DataCollectorInterface $dataCollector
 */
class SwaggerService
{
    use GetDependenciesTrait;

    protected $annotationReader;
    protected $dataCollector;

    protected $data;
    protected $container;
    private $uri;
    private $method;
    /**
     * @var Request
     */
    private $request;
    private $item;
    private $security;

    /**
     * @throws DataCollectorClassNotFoundException
     * @throws WrongSecurityConfigException
     */
    public function __construct(Container $container)
    {
        $this->setDataCollector();

        if (config('app.env') == 'testing') {
            $this->container = $container;

            $this->annotationReader = new AnnotationReader(new Parser, new ArrayCache);

            $this->security = config('auto-doc.security');

            $this->data = $this->dataCollector->getTmpData();

            if (empty($this->data)) {
                $this->data = $this->generateEmptyData();

                $this->dataCollector->saveTmpData($this->data);
            }
        }
    }

    /**
     * @throws DataCollectorClassNotFoundException
     */
    protected function setDataCollector()
    {
        $dataCollectorClass = config('auto-doc.data_collector');

        if (empty($dataCollectorClass)) {
            $this->dataCollector = app(LocalDataCollector::class);
        } elseif (!class_exists($dataCollectorClass)) {
            throw new DataCollectorClassNotFoundException();
        } else {
            $this->dataCollector = app($dataCollectorClass);
        }
    }

    /**
     * @throws WrongSecurityConfigException
     */
    protected function generateEmptyData(): array
    {
        $newData = [
            'swagger' => config('auto-doc.swagger.version'),
            'host' => $this->getAppUrl(),
            'basePath' => config('auto-doc.basePath'),
            'schemes' => config('auto-doc.schemes'),
            'paths' => [],
            'definitions' => config('auto-doc.definitions')
        ];

        $info = $this->prepareInfo(config('auto-doc.info'));
        if (!empty($info)) {
            $newData['info'] = $info;
        }

        $securityDefinitions = $this->generateSecurityDefinition();
        if (!empty($securityDefinitions)) {
            $newData['securityDefinitions'] = $securityDefinitions;
        }

        $newData['info']['description'] = view($newData['info']['description'])->render();

        return $newData;
    }

    protected function getAppUrl()
    {
        $url = config('app.url');

        return str_replace(['http://', 'https://', '/'], '', $url);
    }

    /**
     * @throws WrongSecurityConfigException
     */
    protected function generateSecurityDefinition()
    {
        $availableTypes = ['jwt', 'laravel', 'token'];

        if (empty($this->security) || $this->security == "null") {
            return '';
        }

        if (!in_array($this->security, $availableTypes)) {
            throw new WrongSecurityConfigException();
        }

        return [
            $this->security => $this->generateSecurityDefinitionObject($this->security)
        ];
    }

    protected function generateSecurityDefinitionObject($type): ?array
    {
        switch ($type) {
            case 'jwt':
                $result = [
                    'type' => 'apiKey',
                    'name' => 'authorization',
                    'in' => 'header'
                ];
                break;
            case 'laravel':
                $result = [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'in' => 'header'
                ];
                break;
            case 'token':
                $result = [
                    'type' => 'apiKey',
                    'name' => 'token',
                    'in' => 'header'
                ];
                break;
            default:
                $result = null;
        }
        return $result;
    }

    /**
     * @throws ReflectionException
     */
    public function addData(Request $request, $response)
    {
        $this->request = $request;

        $this->prepareItem();

        $this->parseRequest();
        $this->parseResponse($response);

        $this->dataCollector->saveTmpData($this->data);
    }

    protected function prepareItem()
    {
        $this->uri = "/{$this->getUri()}";
        $this->method = strtolower($this->request->getMethod());

        if (empty(Arr::get($this->data, "paths.$this->uri.$this->method"))) {
            $this->data['paths'][$this->uri][$this->method] = [
                'tags' => [],
                'consumes' => [],
                'produces' => [],
                'parameters' => $this->getPathParams(),
                'responses' => [],
                'security' => [],
                'description' => ''
            ];
        }

        $this->item = &$this->data['paths'][$this->uri][$this->method];
    }

    protected function getUri()
    {
        $requestUri = $this->request->route()->uri();
        if(is_null($requestUri)){
            return '/failed';
        }
        $basePath = preg_replace("/^\//", '', config('auto-doc.basePath'));
        $basePath = preg_quote($basePath, '/');
        $preparedUri = preg_replace("/^$basePath/", '', $requestUri);

        return preg_replace("/^\//", '', $preparedUri);
    }

    protected function getPathParams(): array
    {
        $params = [];

        preg_match_all('/{.*?}/', $this->uri, $params);

        $params = Arr::collapse($params);

        $result = [];

        foreach ($params as $param) {
            $key = preg_replace('/[{}]/', '', $param);

            $result[] = [
                'in' => 'path',
                'name' => $key,
                'description' => '',
                'required' => true,
                'type' => 'string'
            ];
        }

        return $result;
    }

    /**
     * @throws ReflectionException
     */
    protected function parseRequest()
    {
        $this->saveConsume();
        $this->saveTags();
        $this->saveSecurity();

        $concreteRequest = $this->getConcreteRequest();

        if (empty($concreteRequest)) {
            $this->item['description'] = '';

            return;
        }

        $annotations = $this->annotationReader->getClassAnnotations($concreteRequest);

        $this->saveParameters($concreteRequest, $annotations);
        $this->saveDescription($concreteRequest, $annotations);
    }

    /**
     * @throws ReflectionException
     */
    protected function parseResponse($response)
    {
        $produceList = $this->data['paths'][$this->uri][$this->method]['produces'];

        $produce = $response->headers->get('Content-type');
        if (is_null($produce)) {
            $produce = 'text/plain';
        }

        if (!in_array($produce, $produceList)) {
            $this->item['produces'][] = $produce;
        }

        $responses = $this->item['responses'];
        $code = $response->getStatusCode();

        if (!in_array($code, $responses)) {
            $this->saveExample(
                $response->getStatusCode(),
                $response->getContent(),
                $produce
            );
        }
    }

    /**
     * @throws ReflectionException
     */
    protected function saveExample($code, $content, $produce)
    {
        $description = $this->getResponseDescription($code);
        $availableContentTypes = [
            'application',
            'text'
        ];
        $explodedContentType = explode('/', $produce);

        if (in_array($explodedContentType[0], $availableContentTypes)) {
            $this->item['responses'][$code] = $this->makeResponseExample($content, $produce, $description);
        } else {
            $this->item['responses'][$code] = '*Unavailable for preview*';
        }
    }

    protected function makeResponseExample($content, $mimeType, $description = ''): array
    {
        $responseExample = [
            'description' => $description
        ];

        if ($mimeType === 'application/json') {
            $responseExample['schema'] = [
                'example' => json_decode($content, true),
            ];
        } elseif ($mimeType === 'application/pdf') {
            $responseExample['schema'] = [
                'example' => base64_encode($content),
            ];
        } else {
            $responseExample['examples']['example'] = $content;
        }

        return $responseExample;
    }

    protected function saveParameters($request, AnnotationsBagInterface $annotations)
    {
        $formRequest = new $request;
        $formRequest->setUserResolver($this->request->getUserResolver());
        $formRequest->setRouteResolver($this->request->getRouteResolver());
        $rules = method_exists($formRequest, 'rules') ? $formRequest->rules() : [];

        $actionName = $this->getActionName($this->uri);

        if (in_array($this->method, ['get', 'delete'])) {
            $this->saveGetRequestParameters($rules, $annotations);
        } else {
            $this->savePostRequestParameters($actionName, $rules, $annotations);
        }
    }

    protected function saveGetRequestParameters($rules, AnnotationsBagInterface $annotations)
    {
        foreach ($rules as $parameter => $rule) {
            $validation = (is_string($rule)) ? explode('|', $rule) : $rule;

            $description = $annotations->get($parameter, implode(', ', $validation));

            $existedParameter = Arr::first($this->item['parameters'], function ($existedParameter) use ($parameter) {
                return $existedParameter['name'] == $parameter;
            });

            if (empty($existedParameter)) {
                $parameterDefinition = [
                    'in' => 'query',
                    'name' => $parameter,
                    'description' => $description,
                    'type' => $this->getParameterType($validation)
                ];
                if (in_array('required', $validation)) {
                    $parameterDefinition['required'] = true;
                }

                $this->item['parameters'][] = $parameterDefinition;
            }
        }
    }

    protected function savePostRequestParameters($actionName, $rules, AnnotationsBagInterface $annotations)
    {
        if ($this->requestHasMoreProperties($actionName)) {
            if ($this->requestHasBody()) {
                $this->item['parameters'][] = [
                    'in' => 'body',
                    'name' => 'body',
                    'description' => '',
                    'required' => true,
                    'schema' => [
                        "\$ref" => "#/definitions/{$actionName}Object"
                    ]
                ];
            }

            $this->saveDefinitions($actionName, $rules, $annotations);
        }
    }

    protected function saveDefinitions($objectName, $rules, $annotations)
    {
        $dataObject = [
            'type' => 'object',
            'properties' => []
        ];
        foreach ($rules as $parameter => $rule) {
            $rulesArray = (is_string($rule)) ? explode('|', $rule) : $rule;
            $parameterType = $this->getParameterType($rulesArray);
            $this->saveParameterType($dataObject, $parameter, $parameterType);
            $this->saveParameterDescription($dataObject, $parameter, $rulesArray, $annotations);

            if (in_array('required', $rulesArray)) {
                $dataObject['required'][] = $parameter;
            }
        }

        $dataObject['example'] = $this->generateExample($dataObject['properties']);
        $this->data['definitions'][$objectName . 'Object'] = $dataObject;
    }

    protected function getParameterType(array $validation): string
    {
        $validationRules = [
            'array' => 'object',
            'boolean' => 'boolean',
            'date' => 'date',
            'digits' => 'integer',
            'email' => 'string',
            'integer' => 'integer',
            'numeric' => 'double',
            'string' => 'string'
        ];

        $parameterType = 'string';

        foreach ($validation as $item) {
            if (in_array($item, array_keys($validationRules))) {
                $parameterType = $validationRules[$item];
                break;
            }
        }

        return $parameterType;
    }

    protected function saveParameterType(&$data, $parameter, $parameterType)
    {
        $data['properties'][$parameter] = [
            'type' => $parameterType,
        ];
    }

    protected function saveParameterDescription(&$data, $parameter, array $rulesArray, AnnotationsBagInterface $annotations)
    {
        $description = $annotations->get($parameter, implode(', ', $rulesArray));
        $data['properties'][$parameter]['description'] = $description;
    }

    protected function requestHasMoreProperties($actionName): bool
    {
        $requestParametersCount = count($this->request->all());

        if (isset($this->data['definitions'][$actionName . 'Object']['properties'])) {
            $objectParametersCount = count($this->data['definitions'][$actionName . 'Object']['properties']);
        } else {
            $objectParametersCount = 0;
        }

        return $requestParametersCount > $objectParametersCount;
    }

    protected function requestHasBody(): bool
    {
        $parameters = $this->data['paths'][$this->uri][$this->method]['parameters'];

        $bodyParamExisted = Arr::where($parameters, function ($value) {
            return $value['name'] == 'body';
        });

        return empty($bodyParamExisted);
    }

    /**
     * @throws ReflectionException
     */
    public function getConcreteRequest()
    {
        $controller = $this->request->route()->getActionName();

        if ($controller == 'Closure') {
            return null;
        }

        $explodedController = explode('@', $controller);

        $class = $explodedController[0];
        $classMethod = $explodedController[1];

        $instance = app($class);
        $route = $this->request->route();

        $parameters = $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(), $instance, $classMethod
        );

        return Arr::first($parameters, function ($key) {
            return preg_match('/Request/', $key);
        });
    }

    public function saveConsume()
    {
        $consumeList = $this->data['paths'][$this->uri][$this->method]['consumes'];
        $consume = $this->request->header('Content-Type');

        if (!empty($consume) && !in_array($consume, $consumeList)) {
            $this->item['consumes'][] = $consume;
        }
    }

    public function saveTags()
    {
        $tagIndex = 1;

        $explodedUri = explode('/', $this->uri);

        $tag = Arr::get($explodedUri, $tagIndex);

        $this->item['tags'] = [$tag];
    }

    public function saveDescription($request, AnnotationsBagInterface $annotations)
    {
        $this->item['summary'] = $this->getSummary($request, $annotations);

        $description = $annotations->get('description');

        if (!empty($description)) {
            $this->item['description'] = $description;
        }
    }

    protected function saveSecurity()
    {
        if ($this->requestSupportAuth()) {
            $this->addSecurityToOperation();
        }
    }

    protected function addSecurityToOperation()
    {
        $methodSecurity = &$this->data['paths'][$this->uri][$this->method]['security'];
        if (empty($methodSecurity)) {
            $methodSecurity[] = [
                "$this->security" => []
            ];
        }
    }

    protected function getSummary($request, AnnotationsBagInterface $annotations)
    {
        $summary = $annotations->get('summary');

        if (empty($summary)) {
            $summary = $this->parseRequestName($request);
        }

        return $summary;
    }

    protected function requestSupportAuth(): bool
    {
        switch ($this->security) {
            case 'jwt':
                $header = $this->request->header('authorization');
                break;
            case 'laravel':
                $header = $this->request->cookie('__ym_uid');
                break;
            case 'token':
                $header = $this->request->header('token');
                break;
            default:
                $header = null;
        }

        return !empty($header);

    }

    protected function parseRequestName($request)
    {
        $explodedRequest = explode('\\', $request);
        $requestName = array_pop($explodedRequest);
        $summaryName = str_replace('Request', '', $requestName);

        $underscoreRequestName = $this->camelCaseToUnderScore($summaryName);

        return preg_replace('/[_]/', ' ', $underscoreRequestName);
    }

    /**
     * @throws ReflectionException
     */
    protected function getResponseDescription($code)
    {
        $concreteRequest = $this->getConcreteRequest();

        return elseChain(
            function () use ($concreteRequest, $code) {
                return empty($concreteRequest) ? Response::$statusTexts[$code] : null;
            },
            function () use ($concreteRequest, $code) {
                return $this->annotationReader->getClassAnnotations($concreteRequest)->get("_$code");
            },
            function () use ($code) {
                return config("auto-doc.defaults.code-descriptions.$code");
            },
            function () use ($code) {
                return Response::$statusTexts[$code];
            }
        );
    }

    protected function getActionName($uri): string
    {
        $action = preg_replace('[\/]', '', $uri);

        return Str::camel($action);
    }

    protected function saveTempData()
    {
        $exportFile = config('auto-doc.files.temporary');
        $jsonData = json_encode($this->data);

        file_put_contents($exportFile, $jsonData);
    }

    public function saveProductionData()
    {
        $this->dataCollector->saveData();
    }

    public function getDocFileContent()
    {
        return $this->dataCollector->getDocumentation();
    }

    protected function camelCaseToUnderScore($input): string
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }
        return implode('_', $ret);
    }

    protected function generateExample($properties): array
    {
        $parameters = $this->replaceObjectValues($this->request->all());
        $example = [];

        $this->replaceNullValues($parameters, $properties, $example);

        return $example;
    }

    protected function replaceObjectValues($parameters): array
    {
        $classNamesValues = [
            File::class => '[uploaded_file]',
        ];

        $parameters = Arr::dot($parameters);
        $returnParameters = [];

        foreach ($parameters as $parameter => $value) {
            if (is_object($value)) {
                $class = get_class($value);

                $value = Arr::get($classNamesValues, $class, $class);
            }

            Arr::set($returnParameters, $parameter, $value);
        }

        return $returnParameters;
    }

    /**
     * NOTE: All functions below are temporary solution for
     * this issue: https://github.com/OAI/OpenAPI-Specification/issues/229
     * We hope swagger developers will resolve this problem in next release of Swagger OpenAPI
     * */

    protected function replaceNullValues($parameters, $types, &$example)
    {
        foreach ($parameters as $parameter => $value) {
            if (is_null($value) && in_array($parameter, $types)) {
                $example[$parameter] = $this->getDefaultValueByType($types[$parameter]['type']);
            } elseif (is_array($value)) {
                $this->replaceNullValues($value, $types, $example[$parameter]);
            } else {
                $example[$parameter] = $value;
            }
        }
    }

    protected function getDefaultValueByType($type)
    {
        $values = [
            'object' => 'null',
            'boolean' => false,
            'date' => "0000-00-00",
            'integer' => 0,
            'string' => '',
            'double' => 0
        ];

        return $values[$type];
    }

    /**
     * @param $info
     * @return mixed
     */
    protected function prepareInfo($info)
    {
        if (empty($info)) {
            return $info;
        }

        foreach ($info['license'] as $key => $value) {
            if (empty($value)) {
                unset($info['license'][$key]);
            }
        }
        if (empty($info['license'])) {
            unset($info['license']);
        }

        return $info;
    }

    protected function throwTraitMissingException()
    {
        $message = "ERROR:\n" .
            "It looks like you did not add AutoDocRequestTrait to your requester. \n" .
            "Please add it or mark in the test that you do not want to collect the \n" .
            "documentation for this case using the skipDocumentationCollecting() method\n";

        fwrite(STDERR, print_r($message, TRUE));

        die;
    }
}
