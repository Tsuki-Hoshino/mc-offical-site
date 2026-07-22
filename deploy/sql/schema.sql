CREATE TABLE IF NOT EXISTS server_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recorded_at DATETIME(3) NOT NULL,
    mspt DECIMAL(10, 2) NULL,
    process_cpu_percent DECIMAL(7, 2) NULL,
    process_memory_bytes BIGINT UNSIGNED NULL,
    host_cpu_percent DECIMAL(7, 2) NULL,
    host_memory_used_bytes BIGINT UNSIGNED NULL,
    host_memory_total_bytes BIGINT UNSIGNED NULL,
    network_receive_rate DECIMAL(20, 2) NULL,
    network_send_rate DECIMAL(20, 2) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_server_metrics_recorded_at (recorded_at),
    KEY idx_server_metrics_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
