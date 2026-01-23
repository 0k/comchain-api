<?php

// Minimal, readable doubles for Cassandra usage

namespace {

class MockCassandraValue {
    private $val;
    public function __construct($val) { $this->val = $val; }
    public function value() { return $this->val; }
}

class MockRow implements \ArrayAccess, \JsonSerializable {
    private $data;
    public function __construct(array $data) {
        $this->data = $data;
        if (isset($data['time'])) {
            $this->data['time'] = new MockCassandraValue($data['time']);
        }
        if (isset($data['receivedat'])) {
            $this->data['receivedat'] = new MockCassandraValue($data['receivedat']);
        }
    }
    public function offsetExists($offset) { return isset($this->data[$offset]); }
    public function offsetGet($offset) { return $this->data[$offset] ?? null; }
    public function offsetSet($offset, $value) { $this->data[$offset] = $value; }
    public function offsetUnset($offset) { unset($this->data[$offset]); }
    public function jsonSerialize() { return $this->data; }
}

// Micro DSL helpers
function tx(string $hash, int $time, int $status = 0, int $direction = 1): array {
    return [
        'hash' => $hash,
        'time' => $time,
        'status' => $status,
        'direction' => $direction,
        'add1' => '0xaddr1',
        'add2' => '0xaddr2',
        'receivedat' => $time,
    ];
}

function page(array $rows, $next = null): \Iterator {
    return new class($rows, $next) implements \Iterator {
        private $rows;
        private $i = 0;
        private $next;
        public function __construct(array $rows, $next) {
            $this->rows = array_map(function ($r) { return new \MockRow($r); }, $rows);
            $this->next = $next;
        }
        public function isLastPage() { return $this->next === null; }
        public function nextPage() { return $this->next; }
        public function current() { return $this->rows[$this->i]; }
        public function key() { return $this->i; }
        public function next() { ++$this->i; }
        public function rewind() { $this->i = 0; }
        public function valid() { return isset($this->rows[$this->i]); }
    };
}

/**
 * Create a mock session
 *
 * @param array $queryMap Map of query patterns to pages
 * @param array $cursors Optional list of valid cursors as [[time, hash], ...]
 */
function session(array $queryMap, array $cursors = null) {
    // If cursors not explicitly provided, extract from queryMap pages
    if ($cursors === null) {
        $cursors = [];
        foreach ($queryMap as $page) {
            if ($page instanceof \Iterator) {
                // Clone and iterate to extract cursors
                $page->rewind();
                while ($page->valid()) {
                    $row = $page->current();
                    $time = $row['time'] instanceof MockCassandraValue ? $row['time']->value() : $row['time'];
                    $cursors[] = [$time, $row['hash']];
                    $page->next();
                }
                $page->rewind();
            }
        }
    }

    return new class($queryMap, $cursors) {
        private $map;
        private $cursors;
        public function __construct(array $map, array $cursors) {
            $this->map = $map;
            $this->cursors = $cursors;
        }
        public function execute($statement, $options) {
            $query = (string)$statement;

            // Handle cursor validation queries
            if (strpos($query, 'time = ?') !== false && strpos($query, 'hash = ?') !== false) {
                $args = $options['arguments'] ?? [];
                if (count($args) >= 3) {
                    $time = $args[1];
                    $hash = $args[2];
                    foreach ($this->cursors as $cursor) {
                        if ($cursor[0] === $time && $cursor[1] === $hash) {
                            return page([['hash' => $hash]]);
                        }
                    }
                }
                return page([]);
            }

            // Handle regular queries
            foreach ($this->map as $needle => $page) {
                if (strpos($query, $needle) !== false) {
                    // Reset iterator for reuse
                    if ($page instanceof \Iterator) {
                        $page->rewind();
                    }
                    return $page;
                }
            }
            return page([]);
        }
    };
}

class MockStatement {
    private $query;
    public function __construct($query) { $this->query = $query; }
    public function __toString(): string { return $this->query; }
}

} // end global namespace

// Namespace shim so Cassandra\SimpleStatement resolves without extension
namespace Cassandra {
    class SimpleStatement extends \MockStatement {
        public function __construct(string $query) { parent::__construct($query); }
    }
}
