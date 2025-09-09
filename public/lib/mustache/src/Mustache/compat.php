<?php

/*
 * This file is part of Mustache.php.
 *
 * (c) 2010-2025 Justin Hileman
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class_alias(\Mustache\Cache::class, \Mustache\Cache::class);
class_alias(\Mustache\Cache\AbstractCache::class, \Mustache\Cache_AbstractCache::class);
class_alias(\Mustache\Cache\FilesystemCache::class, \Mustache\Cache_FilesystemCache::class);
class_alias(\Mustache\Cache\NoopCache::class, \Mustache\Cache_NoopCache::class);
class_alias(\Mustache\Compiler::class, \Mustache\Compiler::class);
class_alias(\Mustache\Context::class, \Mustache\Context::class);
class_alias(\Mustache\Engine::class, \Mustache\Engine::class);
class_alias(\Mustache\Exception::class, \Mustache\Exception::class);
class_alias(\Mustache\Exception\InvalidArgumentException::class, \Mustache\Exception_InvalidArgumentException::class);
class_alias(\Mustache\Exception\LogicException::class, \Mustache\Exception_LogicException::class);
class_alias(\Mustache\Exception\RuntimeException::class, \Mustache\Exception_RuntimeException::class);
class_alias(\Mustache\Exception\SyntaxException::class, \Mustache\Exception_SyntaxException::class);
class_alias(\Mustache\Exception\UnknownFilterException::class, \Mustache\Exception_UnknownFilterException::class);
class_alias(\Mustache\Exception\UnknownHelperException::class, \Mustache\Exception_UnknownHelperException::class);
class_alias(\Mustache\Exception\UnknownTemplateException::class, \Mustache\Exception_UnknownTemplateException::class);
class_alias(\Mustache\HelperCollection::class, \Mustache\HelperCollection::class);
class_alias(\Mustache\LambdaHelper::class, \Mustache\LambdaHelper::class);
class_alias(\Mustache\Loader::class, \Mustache\Loader::class);
class_alias(\Mustache\Loader\ArrayLoader::class, \Mustache\Loader_ArrayLoader::class);
class_alias(\Mustache\Loader\CascadingLoader::class, \Mustache\Loader_CascadingLoader::class);
class_alias(\Mustache\Loader\FilesystemLoader::class, \Mustache\Loader_FilesystemLoader::class);
class_alias(\Mustache\Loader\InlineLoader::class, \Mustache\Loader_InlineLoader::class);
class_alias(\Mustache\Loader\MutableLoader::class, \Mustache\Loader_MutableLoader::class);
class_alias(\Mustache\Loader\ProductionFilesystemLoader::class, \Mustache\Loader_ProductionFilesystemLoader::class);
class_alias(\Mustache\Loader\StringLoader::class, \Mustache\Loader_StringLoader::class);
class_alias(\Mustache\Logger::class, \Mustache\Logger::class);
class_alias(\Mustache\Logger\AbstractLogger::class, \Mustache\Logger_AbstractLogger::class);
class_alias(\Mustache\Logger\StreamLogger::class, \Mustache\Logger_StreamLogger::class);
class_alias(\Mustache\Parser::class, \Mustache\Parser::class);
class_alias(\Mustache\Source::class, \Mustache\Source::class);
class_alias(\Mustache\Source\FilesystemSource::class, \Mustache\Source_FilesystemSource::class);
class_alias(\Mustache\Template::class, \Mustache\Template::class);
class_alias(\Mustache\Tokenizer::class, \Mustache\Tokenizer::class);

if (!class_exists(\Mustache\Engine::class)) {
    /** @deprecated use Mustache\Engine */
    class Mustache\Engine extends \Mustache\Engine
    {
    }
}

if (!interface_exists(\Mustache\Cache::class)) {
    /** @deprecated use Mustache\Cache */
    interface Mustache\Cache extends \Mustache\Cache
    {
    }
}

if (!class_exists(\Mustache\Cache_AbstractCache::class)) {
    /** @deprecated use Mustache\Cache\AbstractCache */
    abstract class Mustache\Cache_AbstractCache extends \Mustache\Cache\AbstractCache
    {
    }
}

if (!class_exists(\Mustache\Cache_FilesystemCache::class)) {
    /** @deprecated use Mustache\Cache\FilesystemCache */
    class Mustache\Cache_FilesystemCache extends \Mustache\Cache\FilesystemCache
    {
    }
}

if (!class_exists(\Mustache\Cache_NoopCache::class)) {
    /** @deprecated use Mustache\Cache\NoopCache */
    class Mustache\Cache_NoopCache extends \Mustache\Cache\NoopCache
    {
    }
}

if (!class_exists(\Mustache\Compiler::class)) {
    /** @deprecated use Mustache\Compiler */
    class Mustache\Compiler extends \Mustache\Compiler
    {
    }
}

if (!class_exists(\Mustache\Context::class)) {
    /** @deprecated use Mustache\Context */
    class Mustache\Context extends \Mustache\Context
    {
    }
}

if (!class_exists(\Mustache\Engine::class)) {
    /** @deprecated use Mustache\Engine */
    class Mustache\Engine extends \Mustache\Engine
    {
    }
}

if (!interface_exists(\Mustache\Exception::class)) {
    /** @deprecated use Mustache\Exception */
    interface Mustache\Exception extends \Mustache\Exception
    {
    }
}

if (!class_exists(\Mustache\Exception_InvalidArgumentException::class)) {
    /** @deprecated use Mustache\Exception\InvalidArgumentException */
    class Mustache\Exception_InvalidArgumentException extends \Mustache\Exception\InvalidArgumentException
    {
    }
}

if (!class_exists(\Mustache\Exception_LogicException::class)) {
    /** @deprecated use Mustache\Exception\LogicException */
    class Mustache\Exception_LogicException extends \Mustache\Exception\LogicException
    {
    }
}

if (!class_exists(\Mustache\Exception_RuntimeException::class)) {
    /** @deprecated use Mustache\Exception\RuntimeException */
    class Mustache\Exception_RuntimeException extends \Mustache\Exception\RuntimeException
    {
    }
}

if (!class_exists(\Mustache\Exception_SyntaxException::class)) {
    /** @deprecated use Mustache\Exception\SyntaxException */
    class Mustache\Exception_SyntaxException extends \Mustache\Exception\SyntaxException
    {
    }
}

if (!class_exists(\Mustache\Exception_UnknownFilterException::class)) {
    /** @deprecated use Mustache\Exception\UnknownFilterException */
    class Mustache\Exception_UnknownFilterException extends \Mustache\Exception\UnknownFilterException
    {
    }
}

if (!class_exists(\Mustache\Exception_UnknownHelperException::class)) {
    /** @deprecated use Mustache\Exception\UnknownHelperException */
    class Mustache\Exception_UnknownHelperException extends \Mustache\Exception\UnknownHelperException
    {
    }
}

if (!class_exists(\Mustache\Exception_UnknownTemplateException::class)) {
    /** @deprecated use Mustache\Exception\UnknownTemplateException */
    class Mustache\Exception_UnknownTemplateException extends \Mustache\Exception\UnknownTemplateException
    {
    }
}

if (!class_exists(\Mustache\HelperCollection::class)) {
    /** @deprecated use Mustache\HelperCollection */
    class Mustache\HelperCollection extends \Mustache\HelperCollection
    {
    }
}

if (!class_exists(\Mustache\LambdaHelper::class)) {
    /** @deprecated use Mustache\LambdaHelper */
    class Mustache\LambdaHelper extends \Mustache\LambdaHelper
    {
    }
}

if (!interface_exists(\Mustache\Loader::class)) {
    /** @deprecated use Mustache\Loader */
    interface Mustache\Loader extends \Mustache\Loader
    {
    }
}

if (!class_exists(\Mustache\Loader_ArrayLoader::class)) {
    /** @deprecated use Mustache\Loader\ArrayLoader */
    class Mustache\Loader_ArrayLoader extends \Mustache\Loader\ArrayLoader
    {
    }
}

if (!class_exists(\Mustache\Loader_CascadingLoader::class)) {
    /** @deprecated use Mustache\Loader\CascadingLoader */
    class Mustache\Loader_CascadingLoader extends \Mustache\Loader\CascadingLoader
    {
    }
}

if (!class_exists(\Mustache\Loader_FilesystemLoader::class)) {
    /** @deprecated use Mustache\Loader\FilesystemLoader */
    class Mustache\Loader_FilesystemLoader extends \Mustache\Loader\FilesystemLoader
    {
    }
}

if (!class_exists(\Mustache\Loader_InlineLoader::class)) {
    /** @deprecated use Mustache\Loader\InlineLoader */
    class Mustache\Loader_InlineLoader extends \Mustache\Loader\InlineLoader
    {
    }
}

if (!interface_exists(\Mustache\Loader_MutableLoader::class)) {
    /** @deprecated use Mustache\Loader\MutableLoader */
    interface Mustache\Loader_MutableLoader extends \Mustache\Loader\MutableLoader
    {
    }
}

if (!class_exists(\Mustache\Loader_ProductionFilesystemLoader::class)) {
    /** @deprecated use Mustache\Loader\ProductionFilesystemLoader */
    class Mustache\Loader_ProductionFilesystemLoader extends \Mustache\Loader\ProductionFilesystemLoader
    {
    }
}

if (!class_exists(\Mustache\Loader_StringLoader::class)) {
    /** @deprecated use Mustache\Loader\StringLoader */
    class Mustache\Loader_StringLoader extends \Mustache\Loader\StringLoader
    {
    }
}

if (!interface_exists(\Mustache\Logger::class)) {
    /** @deprecated use Mustache\Logger */
    interface Mustache\Logger extends \Mustache\Logger
    {
    }
}

if (!class_exists(\Mustache\Logger_AbstractLogger::class)) {
    /** @deprecated use Mustache\Logger\AbstractLogger */
    abstract class Mustache\Logger_AbstractLogger extends \Mustache\Logger\AbstractLogger
    {
    }
}

if (!class_exists(\Mustache\Logger_StreamLogger::class)) {
    /** @deprecated use Mustache\Logger\StreamLogger */
    class Mustache\Logger_StreamLogger extends \Mustache\Logger\StreamLogger
    {
    }
}

if (!class_exists(\Mustache\Parser::class)) {
    /** @deprecated use Mustache\Parser */
    class Mustache\Parser extends \Mustache\Parser
    {
    }
}

if (!interface_exists(\Mustache\Source::class)) {
    /** @deprecated use Mustache\Source */
    interface Mustache\Source extends \Mustache\Source
    {
    }
}

if (!class_exists(\Mustache\Source_FilesystemSource::class)) {
    /** @deprecated use Mustache\Source\FilesystemSource */
    class Mustache\Source_FilesystemSource extends \Mustache\Source\FilesystemSource
    {
    }
}

if (!class_exists(\Mustache\Template::class)) {
    /** @deprecated use Mustache\Template */
    abstract class Mustache\Template extends \Mustache\Template
    {
    }
}

if (!class_exists(\Mustache\Tokenizer::class)) {
    /** @deprecated use Mustache\Tokenizer */
    class Mustache\Tokenizer extends \Mustache\Tokenizer
    {
    }
}
