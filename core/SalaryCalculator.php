<?php
/**
 * Salary Calculator
 * ERP v2 - HR Module
 * 
 * Handles salary calculations, rate conversions, and allowances
 */

class SalaryCalculator {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Get current active salary for a person
     */
    public function getCurrentSalary(int $peopleId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM people_salary_history 
            WHERE people_id = ? AND is_active = 1 
            ORDER BY effective_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$peopleId]);
        $salary = $stmt->fetch();
        return $salary ?: null;
    }
    
    /**
     * Calculate hourly rate from monthly salary
     */
    public function calculateHourlyRate(float $monthlySalary, int $daysPerMonth = 22, float $hoursPerDay = 8.0): float {
        if ($monthlySalary <= 0 || $daysPerMonth <= 0 || $hoursPerDay <= 0) {
            return 0;
        }
        return $monthlySalary / ($daysPerMonth * $hoursPerDay);
    }
    
    /**
     * Calculate daily rate from monthly salary
     */
    public function calculateDailyRate(float $monthlySalary, int $daysPerMonth = 22): float {
        if ($monthlySalary <= 0 || $daysPerMonth <= 0) {
            return 0;
        }
        return $monthlySalary / $daysPerMonth;
    }
    
    /**
     * Calculate hourly rate from daily rate
     */
    public function dailyToHourlyRate(float $dailyRate, float $hoursPerDay = 8.0): float {
        if ($dailyRate <= 0 || $hoursPerDay <= 0) {
            return 0;
        }
        return $dailyRate / $hoursPerDay;
    }
    
    /**
     * Get effective hourly rate for a person (auto-convert based on salary type)
     */
    public function getHourlyRate(int $peopleId): float {
        $salary = $this->getCurrentSalary($peopleId);
        if (!$salary) {
            return 0;
        }
        
        switch ($salary['salary_type']) {
            case 'Hourly':
                return (float) $salary['hourly_rate'];
                
            case 'Daily':
                return $this->dailyToHourlyRate(
                    (float) $salary['daily_rate'],
                    (float) $salary['standard_hours_per_day']
                );
                
            case 'Monthly':
            default:
                return $this->calculateHourlyRate(
                    (float) $salary['base_salary'],
                    (int) $salary['standard_days_per_month'],
                    (float) $salary['standard_hours_per_day']
                );
        }
    }
    
    /**
     * Calculate total allowances
     */
    public function calculateTotalAllowances(array $salary): float {
        return (float) ($salary['position_allowance'] ?? 0)
             + (float) ($salary['transport_allowance'] ?? 0)
             + (float) ($salary['meal_allowance'] ?? 0)
             + (float) ($salary['housing_allowance'] ?? 0)
             + (float) ($salary['other_allowance'] ?? 0);
    }
    
    /**
     * Calculate social security contribution
     */
    public function calculateSocialSecurity(float $baseSalary, float $rate = 5.0, float $maxSalary = 15000.0): float {
        $cappedSalary = min($baseSalary, $maxSalary);
        return $cappedSalary * ($rate / 100);
    }
    
    /**
     * Calculate withholding tax (simplified monthly calculation)
     * For accurate calculation, use annual income
     */
    public function calculateWithholdingTax(float $monthlyIncome): float {
        $annualIncome = $monthlyIncome * 12;
        
        // Progressive tax brackets (2024)
        $tax = 0;
        if ($annualIncome > 5000000) {
            $tax += ($annualIncome - 5000000) * 0.35;
            $annualIncome = 5000000;
        }
        if ($annualIncome > 2000000) {
            $tax += ($annualIncome - 2000000) * 0.30;
            $annualIncome = 2000000;
        }
        if ($annualIncome > 1000000) {
            $tax += ($annualIncome - 1000000) * 0.25;
            $annualIncome = 1000000;
        }
        if ($annualIncome > 750000) {
            $tax += ($annualIncome - 750000) * 0.20;
            $annualIncome = 750000;
        }
        if ($annualIncome > 500000) {
            $tax += ($annualIncome - 500000) * 0.15;
            $annualIncome = 500000;
        }
        if ($annualIncome > 300000) {
            $tax += ($annualIncome - 300000) * 0.10;
            $annualIncome = 300000;
        }
        if ($annualIncome > 150000) {
            $tax += ($annualIncome - 150000) * 0.05;
        }
        
        // Return monthly portion
        return $tax / 12;
    }
    
    /**
     * Add new salary record (auto-deactivates previous via trigger)
     */
    public function addSalaryRecord(array $data): array {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO people_salary_history (
                    people_id, effective_date, salary_type,
                    base_salary, daily_rate, hourly_rate,
                    position_allowance, transport_allowance, meal_allowance, 
                    housing_allowance, other_allowance, allowance_notes,
                    ot_rate_multiplier, holiday_rate_multiplier,
                    payment_frequency, bank_name, bank_account, bank_branch,
                    standard_hours_per_day, standard_days_per_week, standard_days_per_month,
                    is_active, change_reason, notes, created_by
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    1, ?, ?, ?
                )
            ");
            
            $stmt->execute([
                $data['people_id'],
                $data['effective_date'],
                $data['salary_type'],
                $data['base_salary'] ?? 0,
                $data['daily_rate'] ?? 0,
                $data['hourly_rate'] ?? 0,
                $data['position_allowance'] ?? 0,
                $data['transport_allowance'] ?? 0,
                $data['meal_allowance'] ?? 0,
                $data['housing_allowance'] ?? 0,
                $data['other_allowance'] ?? 0,
                $data['allowance_notes'] ?? null,
                $data['ot_rate_multiplier'] ?? 1.5,
                $data['holiday_rate_multiplier'] ?? 2.0,
                $data['payment_frequency'] ?? 'Monthly',
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['bank_branch'] ?? null,
                $data['standard_hours_per_day'] ?? 8.0,
                $data['standard_days_per_week'] ?? 5,
                $data['standard_days_per_month'] ?? 22,
                $data['change_reason'] ?? null,
                $data['notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            $salaryId = $this->db->lastInsertId();
            
            // Audit log
            $audit = new AuditLog();
            $audit->log('create', 'SALARY', $salaryId, null, $data);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $salaryId];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get salary history for a person
     */
    public function getSalaryHistory(int $peopleId): array {
        $stmt = $this->db->prepare("
            SELECT sh.*, u.full_name as created_by_name
            FROM people_salary_history sh
            LEFT JOIN users u ON sh.created_by = u.id
            WHERE sh.people_id = ?
            ORDER BY sh.effective_date DESC, sh.created_at DESC
        ");
        $stmt->execute([$peopleId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get salary summary for all active employees
     */
    public function getSalarySummary(): array {
        $stmt = $this->db->query("
            SELECT 
                p.id, p.name, p.position, p.employment_type,
                sh.salary_type, sh.base_salary, sh.daily_rate, sh.hourly_rate,
                sh.effective_date,
                (sh.position_allowance + sh.transport_allowance + sh.meal_allowance + 
                 sh.housing_allowance + sh.other_allowance) as total_allowances
            FROM people p
            LEFT JOIN people_salary_history sh ON p.id = sh.people_id AND sh.is_active = 1
            WHERE p.status = 'Active'
            ORDER BY p.name
        ");
        return $stmt->fetchAll();
    }
}
