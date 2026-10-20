# PHP library for distributed locking
Distributed exclusive and shared resource locking based on [etcd](https://github.com/etcd-io/etcd) (using [aternos/etcd](https://github.com/aternosorg/php-etcd)).

### About
This library was created to provide a highly available distributed locking solution for a file storage system,
where a failed or incorrect lock could lead to potential data loss or corruption. Therefore it tries to achieve
the best possible consistency while still providing simple usability, stability and scalability. There are lots 
of other locking libraries out there. Some of them don't allow shared locks, some of them implement complicated 
algorithms on the client-side which also didn't seem to fit our needs. Therefore we've decided to create our own solution 
and let etcd do most of the heavy lifting.

Etcd is a fast and reliable key-value store that allows transaction operations to ensure that no unexpected changes were made 
since the last read (see `putIf()` and `deleteIf()` in [`Aternos\Etcd\Client`](https://github.com/aternosorg/php-etcd/blob/master/src/Client.php)).
This locking library uses those methods to ensure consistency. If two processes try to lock the same resource at the same
time one of them will be denied by etcd and then retry its lock by either adding it to the other lock (shared) or waiting
for the other lock to finish (exclusive). If more processes are fighting over the same lock, they will start to delay their
retries in random intervals to find a consistent conclusion.

Temporary storage errors (e.g. `UnavailableException`s from etcd) will also be detected and the operations retried after
some delay to avoid problems because of short availability problems.

### Installation
This library requires PHP 8.5 or newer. The gRPC PHP extension has to be installed to use the default etcd storage.
See [aternos/etcd](https://github.com/aternosorg/php-etcd#installation).

```bash
composer require aternos/lock
```

## Usage
The most important class is the [`Lock`](src/Lock.php) class. Each instance of this class represents
one lock on a resource that is identified by a key. All options of a lock can be passed to the constructor or set
using setters, but all of them have default values, so you can just start by creating a lock:

```php
<?php

$lock = new \Aternos\Lock\Lock("key");

// try to acquire lock
if ($lock->lock()) {
    // do something
} else {
    // error/exit
}
```

### Exclusive/shared locks
There can only be one exclusive lock at the same time, but there can be multiple shared locks. If there is
any shared lock an exclusive lock is not possible. Exclusive locks can be useful for write operations while
shared locks can be useful for read operations. By default all locks are shared, you can create an exclusive
lock like this:

```php
<?php

$lock = new \Aternos\Lock\Lock("key", exclusive: true);

// or
$lock = new \Aternos\Lock\Lock("key");
$lock->setExclusive(true);
```

### Locking time(out) and refreshing
You always have to set a timeout for your locks (default: 120 seconds). The lock will be released automatically if the
time runs out. This is necessary to avoid infinite dead locks. The timeout can be refreshed e.g. after writing or
reading a chunk of data.

By default, a refresh sets the timeout to the initial locking time again. You can set a different refresh time. You can
also define a refresh threshold relative to the refresh time (default: 0.5). The lock will only be refreshed if the
remaining locking time is below this threshold. This avoids spamming the storage with unnecessary queries.

```php
<?php

$lock = new \Aternos\Lock\Lock("key", time: 60); // 60 seconds timeout
$lock->lock();

// refresh the lock, only if the remaining time is lower than 30 seconds (0.5 * 60 seconds)
$lock->refresh();

// refresh with 120 seconds timeout, but only if the remaining time is lower than 30 seconds (0.25 * 120 seconds)
$lock->setRefreshTime(120)
    ->setRefreshThreshold(0.25)
    ->refresh();
```

### Waiting for other locks
If the resource that you want to access is currently locked exclusively or you need an exclusive lock and there
are still shared locks, you might want to wait some time for the locks to be released. You can specify a maximum
time to wait for other locks (default: 300 seconds):

```php
<?php

$lock = new \Aternos\Lock\Lock("key", exclusive: true, time: 60, waitTime: 300); // wait 300 seconds for other locks

// check if the lock was actually acquired or if the wait timeout was reached
if ($lock->lock()) {
    // do something
} else {
    // error/exit
}
```

Only locks that run out within the wait time are waited for. If an existing lock is valid for longer than the wait time,
`lock()` returns immediately.

### Break the lock
Of course it's important to break the lock after finishing the operation to avoid running into timeouts. You can break
a lock like this:

```php
<?php

$lock = new \Aternos\Lock\Lock("key");

// check if the lock was successful
if ($lock->lock()) {
    // do something
    $lock->break();
} else {
    // error/exit
}
```

By default, the lock is also broken automatically when the `Lock` object is destroyed. You can disable this by passing
`breakOnDestruct: false` to the constructor or by calling `$lock->setBreakOnDestruct(false)`.

### Identifier
You can identify yourself (the current process/request) by providing an identifier string. By default this library
uses the `uniqid()` function to create a unique identifier. There aren't many reasons to provide a custom identifier.
The default identifier is the same for all locks in the current process. Therefore all those locks are able to take
over the other locks that were previously created in the same process. You can see which other processes (represented
by their identifier) are currently holding a lock using `$lock->getLocks()`.

There are two ways to provide a custom identifier. Either by setting the default for all locks or by overwriting
the default identifier on a specific lock:

```php
<?php

\Aternos\Lock\Lock::setDefaultIdentifier("default-identifier");

// uses the previously set "default-identifier"
$lock = new \Aternos\Lock\Lock("key");

// overwrites the "default-identifier" with "different-identifier"
$lock = new \Aternos\Lock\Lock("key", "different-identifier");
```

### Settings
The default identifier above is already one of the static settings, which you can specify for all locks. All settings
are stored in protected static fields in the [`Lock`](src/Lock.php) class (at the top) and have their own static
setter functions. Below are only a few important ones, but you can read the PHPDoc function comments for further explanations
of the other settings. Only change those if you know what you are doing.

#### Storage
Locks are stored in a [`StorageInterface`](src/Storage/StorageInterface.php). By default, an
[`EtcdStorage`](src/Storage/EtcdStorage.php) connecting to etcd on `localhost:2379` is used. To specify the etcd
connection details, create an etcd client and pass it to the storage. See [this](https://github.com/aternosorg/php-etcd#client-class)
for more information.

```php
<?php

$client = new \Aternos\Etcd\Client("localhost:2379", "username", "password");

\Aternos\Lock\Lock::setStorage(new \Aternos\Lock\Storage\EtcdStorage($client));
```

You can also implement your own storage using the [`StorageInterface`](src/Storage/StorageInterface.php). For unit tests,
you can use the [`SimpleInMemoryStorage`](src/Storage/SimpleInMemoryStorage.php), which does not require etcd:

```php
<?php

$storage = new \Aternos\Lock\Storage\SimpleInMemoryStorage();
\Aternos\Lock\Lock::setStorage($storage);

// e.g. after each test
$storage->clear();
```

#### Key prefix
You can set a prefix for all locking keys in the storage to avoid conflicts with other data in the same etcd cluster.
The default prefix is `lock/`.

```php
<?php

\Aternos\Lock\Lock::setPrefix("my-prefix/");
```

## Upgrading to v4
- PHP 8.5 or newer is required.
- [aternos/etcd](https://github.com/aternosorg/php-etcd) v2 is required. If you pass your own `Aternos\Etcd\ClientInterface`
  implementation to `EtcdStorage`, update it to v2. Missing keys are now represented by `null` instead of `false`.
