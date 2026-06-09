USE kwarta;

CREATE TABLE IF NOT EXISTS savings_goal_histories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    savings_goal_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    amount_changed DECIMAL(12, 2),
    previous_amount DECIMAL(12, 2),
    new_amount DECIMAL(12, 2),
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_savings_history_goal FOREIGN KEY (savings_goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE,
    INDEX idx_savings_history_goal_created (savings_goal_id, created_at)
) ENGINE=InnoDB;
