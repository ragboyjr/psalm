<?php
namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\Internal\Codebase\CallMap;
use Psalm\Context;
use Psalm\Type;
use function strtolower;
use function array_values;
use function count;
use function is_string;

/**
 * @internal
 */
class FunctionAnalyzer extends FunctionLikeAnalyzer
{
    /**
     * @var PhpParser\Node\Stmt\Function_
     */
    protected $function;

    public function __construct(PhpParser\Node\Stmt\Function_ $function, SourceAnalyzer $source)
    {
        $codebase = $source->getCodebase();

        $file_storage_provider = $codebase->file_storage_provider;

        $file_storage = $file_storage_provider->get($source->getFilePath());

        $namespace = $source->getNamespace();

        $function_id = ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($function->name->name);

        if (!isset($file_storage->functions[$function_id])) {
            throw new \UnexpectedValueException(
                'Function ' . $function_id . ' should be defined in ' . $source->getFilePath()
            );
        }

        $storage = $file_storage->functions[$function_id];

        parent::__construct($function, $source, $storage);
    }

    /**
     * @param  string                      $function_id
     * @param  array<PhpParser\Node\Arg>   $call_args
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMapWithArgs(
        StatementsAnalyzer $statements_analyzer,
        $function_id,
        array $call_args,
        Context $context
    ) {
        $call_map_key = strtolower($function_id);

        $call_map = CallMap::getCallMap();

        $codebase = $statements_analyzer->getCodebase();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if (!$call_args) {
            switch ($call_map_key) {
                case 'hrtime':
                    return new Type\Union([
                        new Type\Atomic\ObjectLike([
                            Type::getInt(),
                            Type::getInt()
                        ])
                    ]);

                case 'get_called_class':
                    return new Type\Union([
                        new Type\Atomic\TClassString(
                            $context->self ?: 'object',
                            $context->self ? new Type\Atomic\TNamedObject($context->self, true) : null
                        )
                    ]);

                case 'get_parent_class':
                    if ($context->self && $codebase->classExists($context->self)) {
                        $classlike_storage = $codebase->classlike_storage_provider->get($context->self);

                        if ($classlike_storage->parent_classes) {
                            return new Type\Union([
                                new Type\Atomic\TClassString(
                                    array_values($classlike_storage->parent_classes)[0]
                                )
                            ]);
                        }
                    }
            }
        } else {
            switch ($call_map_key) {
                case 'count':
                    if (($first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value))) {
                        $atomic_types = $first_arg_type->getAtomicTypes();

                        if (count($atomic_types) === 1) {
                            if (isset($atomic_types['array'])) {
                                if ($atomic_types['array'] instanceof Type\Atomic\TCallableArray
                                    || $atomic_types['array'] instanceof Type\Atomic\TCallableList
                                    || $atomic_types['array'] instanceof Type\Atomic\TCallableObjectLikeArray
                                ) {
                                    return Type::getInt(false, 2);
                                }

                                if ($atomic_types['array'] instanceof Type\Atomic\TNonEmptyArray) {
                                    return new Type\Union([
                                        $atomic_types['array']->count !== null
                                            ? new Type\Atomic\TLiteralInt($atomic_types['array']->count)
                                            : new Type\Atomic\TInt
                                    ]);
                                }

                                if ($atomic_types['array'] instanceof Type\Atomic\TNonEmptyList) {
                                    return new Type\Union([
                                        $atomic_types['array']->count !== null
                                            ? new Type\Atomic\TLiteralInt($atomic_types['array']->count)
                                            : new Type\Atomic\TInt
                                    ]);
                                }

                                if ($atomic_types['array'] instanceof Type\Atomic\ObjectLike
                                    && $atomic_types['array']->sealed
                                ) {
                                    return new Type\Union([
                                        new Type\Atomic\TLiteralInt(count($atomic_types['array']->properties))
                                    ]);
                                }
                            }
                        }
                    }

                    break;

                case 'hrtime':
                    if (($first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value))) {
                        if ((string) $first_arg_type === 'true') {
                            $int = Type::getInt();
                            $int->from_calculation = true;
                            return $int;
                        }

                        if ((string) $first_arg_type === 'false') {
                            return new Type\Union([
                                new Type\Atomic\ObjectLike([
                                    Type::getInt(),
                                    Type::getInt()
                                ])
                            ]);
                        }

                        return new Type\Union([
                            new Type\Atomic\ObjectLike([
                                Type::getInt(),
                                Type::getInt()
                            ]),
                            new Type\Atomic\TInt()
                        ]);
                    }

                    $int = Type::getInt();
                    $int->from_calculation = true;
                    return $int;

                case 'get_parent_class':
                    // this is unreliable, as it's hard to know exactly what's wanted - attempted this in
                    // https://github.com/vimeo/psalm/commit/355ed831e1c69c96bbf9bf2654ef64786cbe9fd7
                    // but caused problems where it didn’t know exactly what level of child we
                    // were receiving.
                    //
                    // Really this should only work on instances we've created with new Foo(),
                    // but that requires more work
                    break;
            }
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        $call_map_return_type = Type::parseString($call_map[$call_map_key][0]);

        switch ($call_map_key) {
            case 'mb_strpos':
            case 'mb_strrpos':
            case 'mb_stripos':
            case 'mb_strripos':
            case 'strpos':
            case 'strrpos':
            case 'stripos':
            case 'strripos':
            case 'strstr':
            case 'stristr':
            case 'strrchr':
            case 'strpbrk':
            case 'array_search':
                break;

            default:
                if ($call_map_return_type->isFalsable()
                    && $codebase->config->ignore_internal_falsable_issues
                ) {
                    $call_map_return_type->ignore_falsable_issues = true;
                }
        }

        switch ($call_map_key) {
            case 'array_replace':
            case 'array_replace_recursive':
                if ($codebase->config->ignore_internal_nullable_issues) {
                    $call_map_return_type->ignore_nullable_issues = true;
                }
                break;
        }

        return $call_map_return_type;
    }

    /**
     * @param  array<PhpParser\Node\Arg>   $call_args
     */
    public static function taintBuiltinFunctionReturn(
        StatementsAnalyzer $statements_analyzer,
        string $function_id,
        array $call_args,
        Type\Union $return_type
    ) : void {
        $codebase = $statements_analyzer->getCodebase();

        if (!$codebase->taint) {
            return;
        }

        switch ($function_id) {
            case 'htmlspecialchars':
                if (($first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value))
                    && $first_arg_type->tainted
                ) {
                    // input is now safe from tainted sql and html
                    $return_type->tainted = $first_arg_type->tainted
                        & ~(Type\Union::TAINTED_INPUT_SQL | Type\Union::TAINTED_INPUT_HTML);
                    $return_type->sources = $first_arg_type->sources;
                }
                break;

            case 'strtolower':
            case 'strtoupper':
            case 'sprintf':
            case 'preg_quote':
            case 'substr':
                if (($first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value))
                    && $first_arg_type->tainted
                ) {
                    $return_type->tainted = $first_arg_type->tainted;
                    $return_type->sources = $first_arg_type->sources;
                }

                break;

            case 'str_replace':
            case 'preg_replace':
                $first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value);
                $third_arg_type = $statements_analyzer->node_data->getType($call_args[2]->value);

                $first_arg_taint = $first_arg_type->tainted ?? 0;
                $third_arg_taint = $third_arg_type->tainted ?? 0;
                if ($first_arg_taint || $third_arg_taint) {
                    $return_type->tainted = $first_arg_taint | $third_arg_taint;
                    $return_type->sources = $first_arg_type->sources ?? [];
                }

                break;

            case 'htmlentities':
            case 'striptags':
                if (($first_arg_type = $statements_analyzer->node_data->getType($call_args[0]->value))
                    && $first_arg_type->tainted
                ) {
                    // input is now safe from tainted html
                    $return_type->tainted = $first_arg_type->tainted
                        & ~Type\Union::TAINTED_INPUT_HTML;
                    $return_type->sources = $first_arg_type->sources;
                }
                break;
        }
    }

    /**
     * @return non-empty-lowercase-string
     */
    public function getFunctionId()
    {
        $namespace = $this->source->getNamespace();

        /** @var non-empty-lowercase-string */
        return ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($this->function->name->name);
    }

    public static function analyzeStatement(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt\Function_ $stmt,
        Context $context
    ) : void {
        foreach ($stmt->stmts as $function_stmt) {
            if ($function_stmt instanceof PhpParser\Node\Stmt\Global_) {
                foreach ($function_stmt->vars as $var) {
                    if ($var instanceof PhpParser\Node\Expr\Variable) {
                        if (is_string($var->name)) {
                            $var_id = '$' . $var->name;

                            // registers variable in global context
                            $context->hasVariable($var_id, $statements_analyzer);
                        }
                    }
                }
            } elseif (!$function_stmt instanceof PhpParser\Node\Stmt\Nop) {
                break;
            }
        }

        $codebase = $statements_analyzer->getCodebase();

        if (!$codebase->register_stub_files
            && !$codebase->register_autoload_files
        ) {
            $function_name = strtolower($stmt->name->name);

            if ($ns = $statements_analyzer->getNamespace()) {
                $fq_function_name = strtolower($ns) . '\\' . $function_name;
            } else {
                $fq_function_name = $function_name;
            }

            $function_context = new Context($context->self);
            $function_context->strict_types = $context->strict_types;
            $config = \Psalm\Config::getInstance();
            $function_context->collect_exceptions = $config->check_for_throws_docblock;

            if ($function_analyzer = $statements_analyzer->getFunctionAnalyzer($fq_function_name)) {
                $function_analyzer->analyze(
                    $function_context,
                    $statements_analyzer->node_data,
                    $context
                );

                if ($config->reportIssueInFile('InvalidReturnType', $statements_analyzer->getFilePath())) {
                    $method_id = $function_analyzer->getId();

                    $function_storage = $codebase->functions->getStorage(
                        $statements_analyzer,
                        strtolower($method_id)
                    );

                    $return_type = $function_storage->return_type;
                    $return_type_location = $function_storage->return_type_location;

                    $function_analyzer->verifyReturnType(
                        $stmt->getStmts(),
                        $statements_analyzer,
                        $return_type,
                        $statements_analyzer->getFQCLN(),
                        $return_type_location,
                        $function_context->has_returned
                    );
                }
            }
        }
    }
}
