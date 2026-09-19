<?php
declare(strict_types=1);
namespace Meydan\Core\Feed;
final class FeedFilter { public function preRank(array $items, FeedContext $context): array { return $items; } }
