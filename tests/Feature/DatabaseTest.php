<?php

use Dsewth\DatabaseHelper\Database;
use Dsewth\DatabaseHelper\Exceptions\DatabaseException;
use Monolog\Logger;

beforeAll(function() {
    $db = Database::fromConfig([
        "hostname" => "127.0.0.1",
        "username" => "mariadb",
        "password" => "mariadb",
        "database" => "mariadb"
    ]);

    $db->fastQuery("DROP TABLE IF EXISTS a");
    $db->close();
});

beforeEach(function() {
    $this->db = Database::fromConfig([
        "hostname" => "127.0.0.1",
        "username" => "mariadb",
        "password" => "mariadb",
        "database" => "mariadb"
    ]);
});

it('can create a new database object and connect to a specific database', function () {
    $db = Database::getInstance();
    expect($db)->toBeInstanceOf(Database::class);
    $db->setConnection(
        new \mysqli(
            "127.0.0.1", 
            "mariadb", 
            "mariadb",
            "mariadb",
        )
    );
    expect($db->connection()->connect_errno)->toBe(0);
    expect($db->close())->toBe(true);
    
    $db = Database::fromConfig([
        "hostname" => "127.0.0.1",
        "username" => "mariadb",
        "password" => "mariadb",
        "database" => "mariadb"
    ]);
    expect($db->connection()->connect_errno)->toBe(0);
    expect($db->close())->toBe(true);

    $db = Database::fromConnection(
        $this->db->connection()
    );
    expect($db->connection()->connect_errno)->toBe(0);
    expect($db->close())->toBe(true);
});

it('can execute a simple query and get results', function () {
    $result = $this->db->fastQuery("SELECT 1")->fetch_row();
    expect($result[0])->toBe("1");
});

it('throws a DatabaseException when executing query with errors', function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    expect(
        fn () => $this->db->fastQuery("SELECT 1 FROM a")->fetch_row()
    )->toThrow(DatabaseException::class);
});

it('can execute a query with parameters and return results', function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    $this->db->fastQuery("CREATE TABLE `a` (`id` INT, `name` varchar(50))");
    for ($i = 0; $i < 10; $i++) {
        $this->db->fastQuery("INSERT INTO `a` VALUES ($i, 'test$i')");
    }

    $result = $this->db->query(
        "SELECT * FROM `a` WHERE `id` = ?", 
        [3]
    )->get_result();
    expect($result)->toBeInstanceOf(\mysqli_result::class);
    expect($result->num_rows)->toBe(1);
    $row = $result->fetch_row();
    expect($row[0])->toBe(3);
    expect($row[1])->toBe("test3");

    $this->db->fastQuery("DROP TABLE a");
});

it("logs errors to a logger of choice", function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    expect(function() {
        $mockLogger = Mockery::mock(Logger::class);
        $mockLogger->shouldReceive('error')->times(3);
        $this->db->setLogger($mockLogger);
        $this->db->fastQuery("SELECT 1 from a");
    })->toThrow(DatabaseException::class);

    expect(function() {
        $mockLogger = Mockery::mock(Logger::class);
        $mockLogger->shouldReceive('error')->times(3);
        $this->db->setLogger($mockLogger);
        $this->db->query("SELECT 1 from a where id =?", [1]);
    })->toThrow(DatabaseException::class);

    expect(function() {
        $mockLogger = Mockery::mock(Logger::class);
        $mockLogger->shouldReceive('error')->times(3);
        $this->db->setLogger($mockLogger);
        $this->db->prepare("SELECT 1 from a where id =?");
        $this->db->execute([1]);
    })->toThrow(DatabaseException::class);

    // expect(function() {
    //     $mockLogger = Mockery::mock(Logger::class);
    //     $mockLogger->shouldReceive('error')->times(3);
    //     $this->db->setLogger($mockLogger);
    //     $this->db->prepare("SELECT ?, ?");
    //     $this->db->execute([1]);
    // })->toThrow(DatabaseException::class);
});

it("throws an exception if we are not connected to a database", function () {
    $db = new Database();
    expect(fn() => $db->fastQuery("SELECT 1"))
        ->toThrow(DatabaseException::class);
    expect(fn() => $db->query("SELECT 1 from a where id =?", [1]))
        ->toThrow(DatabaseException::class);
});

it("can prepare a query and execute it", function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    $this->db->prepare("SELECT 1");
    $this->db->execute();
    $result = $this->db->statement()->get_result()->fetch_all();

    expect($result[0][0])->toBe(1);
    expect($result)->toHaveCount(1);
    $this->db->close_statement();

    $this->db->fastQuery("CREATE TABLE `a` (`id` INT, `name` varchar(50))");
    for ($i = 0; $i < 10; $i++) {
        $this->db->fastQuery("INSERT INTO `a` VALUES ($i, 'test$i')");
    }

    $this->db->prepare(
        "SELECT * FROM `a` WHERE `id` = ?"
    );
    $this->db->execute([3]);
    $result = $this->db->statement()->get_result()->fetch_all();
    expect($result[0][0])->toBe(3);
    expect($result[0][1])->toBe("test3");
    expect($result)->toHaveCount(1);

    $this->db->fastQuery("DROP TABLE a");
});

it("throws an exception if you try to execute before preparing a query", function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    expect(fn() => $this->db->execute([1]))->toThrow(DatabaseException::class);
});

it("returns true if you try to close a statement that doesn't exist", function () {
    expect($this->db)->toBeInstanceOf(Database::class);
    expect($this->db->close_statement())->toBe(true);
});