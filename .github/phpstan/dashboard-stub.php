<?php
// Nur für PHPStan: Signaturen des AddOns „dashboard“ (FriendsOfREDAXO), das optional ist.
namespace FriendsOfRedaxo\Dashboard\Base {
    abstract class Item {
        protected function __construct($id, $name) {}
        /** @return mixed */
        abstract protected function getData();
        /** @return static */
        public static function factory($id, $name) { return new static($id, $name); }
        /** @return $this */
        public function setColumns(int $colCount) { return $this; }
    }
}
namespace FriendsOfRedaxo\Dashboard {
    class Dashboard { public static function addItem(Base\Item $item): void {} }
}
