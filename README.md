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

Timeouts (UnavailableExceptions) from etcd will also be detected and the operations retried after some delay to avoid
problems because of short availability problems.

This library was extracted from a different project to be used in different places and not only for a file storage.
Therefore we've already used this in production to create thousands of locks every minute in only a few milliseconds.

### Installation
The gRPC PHP extension has to be installed to use this library. See [aternos/etcd](https://github.com/aternosorg/php-etcd#installation).

```bash
composer require aternos/lock
```

## Usage
The most important class is the [`Lock`](src/Lock.php) class. Each instance of this class represents
one lock on a resource that is identified by a key. There are also several static functions to set options
for all locks, but all of them have default values, so you can just start by creating a lock:

```php
<?php

$lock = new \Aternos\Lock\Lock("key");

// try to acquire lock
if($lock->lock()) {
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

$lock->lock(true);
```

### Locking time(out) and refreshing
You always have to set a timeout for your locks. The lock will be released automatically if the time runs out.
This is necessary to avoid infinite dead locks. The timeout can be refreshed e.g. after writing or reading a
chunk of data. You can also define a threshold for the refresh time. The lock will only be refreshed if
the remaining locking time is below this threshold. This avoids spamming etcd with unnecessary queries.

```php
<?php 

$lock->lock(true, 60); // 60 seconds timeout

// refresh the lock (with default values)
$lock->refresh();

// refresh with 120 seconds timeout
$lock->refresh(120);

// refresh with 120 seconds timeout, but only if remaining time is lower than 60 seconds
$lock->refresh(120, 60);
```

### Waiting for other locks
If the resource that you want to access is currently locked exclusively or you need an exclusive lock and there
are still shared locks, you might want to wait some time for the locks to be released. You can specify a maximum
time to wait for other locks:

```php
<?php 

$lock->lock(true, 60, 300); // wait 300 seconds for other locks

// check if the lock was actually acquired or if the wait timeout was reached
if($lock->isLocked()) {
    // do something
} else {
    // error/exit
}
```

### Break the lock
Of course it's important to break the lock after finishing the operation to avoid running into timeouts. You can break
a lock like this:

```php
<?php

$lock = new \Aternos\Lock\Lock("key");

// check if the lock was successful
if($lock->isLocked()) {
    // do something
    $lock->break();
} else {
    // error/exit
}
```

### Identifier
You can identify yourself (the current process/request) by providing and identifier string. By default this library
uses the `uniqid()` function to create a unique identifier. There aren't many reasons to provide a custom identifier.
The default identifier is the same for all locks in the current process. Therefore all those locks are able to take
over the other locks that were previously created in the same process. It also would be possible to see which other
process (represented by its identifier) is currently holding a lock. There is currently no such function, but that 
would be easy to add.

There are two ways to provide a custom identifier. Either by setting the default for all locks or by overwriting 
the default identifier on a specific lock:

```php
<?php

\Aternos\Lock\Lock::setDefaultIdentifier("default-identifier");

// uses the previously set "default-identifier"
$lock->lock();

// overwrites the "default-identifier" with "different-identifier"
$lock->lock(true, 60, 300, "different-identifier");
```

### Settings
The default identifier above is already one of the static settings, which you can specify for all locks. All settings 
are stored in protected static fields in the [`Lock`](src/Lock.php) class (at the top) and have their own static
setter functions. Below are only a few important ones, but you can read the PHPDoc function comments for further explanations
of the other settings. Only change those if you know what you are doing.

#### Etcd client
You can set an etcd client object to specify the etcd connection details. See [this](https://github.com/aternosorg/php-etcd#client-class)
for more information.

```php
<?php

$client = new Aternos\Etcd\Client();
$client = new Aternos\Etcd\Client("localhost:2379");
$client = new Aternos\Etcd\Client("localhost:2379", "username", "password");

\Aternos\Lock\Lock::setClient($client);
```

#### Etcd key prefix
You can set a prefix for all locking keys in etcd to avoid conflicts with other data in the same etcd cluster.
The default prefix is `lock/`.

```php
<?php

\Aternos\Lock\Lock::setPrefix("my-prefix/");
```
