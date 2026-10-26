<?php
namespace Lab;

use Faker\Generator;
use PDO;

final class CustomerSeeder
{
    public function __construct(
        private PDO $db,
        private Generator $faker
    ) {}

    public function seed(int $count = 10000): void
    {
        $checkStmt = $this->db->query("SELECT COUNT(*) FROM customers");
        $existing = (int) $checkStmt->fetchColumn();
        if ($existing >= $count) {
            echo "Customers table already contains {$existing} records. Preserving existing records.\n";
            return;
        }

        echo "Seeding {$count} customers with Faker...\n";
        $createdAt = gmdate('Y-m-d H:i:s');
        
        $sql = "INSERT IGNORE INTO customers (customer_id, full_name, email, marketing_opt_in, created_at) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        $this->db->beginTransaction();
        for ($i = 1; $i <= $count; $i++) {
            $customerId = sprintf('CUST-%05d', $i);
            $email = sprintf('customer%05d@example.com', $i);

            // TODO S1: Generate a name using the injected Faker object
            $name = $this->faker->name();

            // TODO S2: Assign consent 1 for customers 1-8,000 and 0 for customers 8,001-10,000
            $consent = ($i <= 8000) ? 1 : 0;

            $stmt->execute([$customerId, $name, $email, $consent, $createdAt]);

            if ($i % 1000 === 0) {
                $this->db->commit();
                $this->db->beginTransaction();
            }
        }
        $this->db->commit();
        echo "Completed seeding {$count} customers.\n";
    }
}
