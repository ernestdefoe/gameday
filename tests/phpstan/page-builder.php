<?php

/*
 * The parts of ernestdefoe/page-builder this extension touches, for PHPStan.
 *
 * Page Builder is an optional, private extension: the blocks are registered
 * only when it is installed (see extend.php), and CI cannot install it. These
 * signatures match page-builder's src/Block/BlockInterface.php,
 * src/Block/AbstractBlock.php and src/Extend/PageBuilderBlock.php. Never loaded
 * at runtime.
 */

namespace Ernestdefoe\PageBuilder\Block {
    use Flarum\User\User;

    interface BlockInterface
    {
        public function type(): string;

        public function name(): string;

        public function icon(): string;

        public function category(): string;

        public function defaultSettings(): array;

        public function settingsSchema(): array;

        public function resolve(array $settings, User $actor): array;
    }

    abstract class AbstractBlock implements BlockInterface
    {
        public function category(): string
        {
            return 'content';
        }

        public function defaultSettings(): array
        {
            return [];
        }

        public function settingsSchema(): array
        {
            return [];
        }

        public function resolve(array $settings, User $actor): array
        {
            return [];
        }
    }
}

namespace Ernestdefoe\PageBuilder\Extend {
    use Flarum\Extend\ExtenderInterface;
    use Flarum\Extension\Extension;
    use Illuminate\Contracts\Container\Container;

    class PageBuilderBlock implements ExtenderInterface
    {
        public function __construct(protected string $blockClass)
        {
        }

        public function extend(Container $container, ?Extension $extension = null): void
        {
        }
    }
}
