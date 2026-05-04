<?php

function run_migrations(array $config): void
{
    $db = Database::conn();

    if (Database::isMysql()) {
        $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(191) PRIMARY KEY,
            value LONGTEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS sip_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            label VARCHAR(255),
            sip_server VARCHAR(255) NOT NULL,
            domain VARCHAR(255),
            proxy VARCHAR(255),
            username VARCHAR(255) NOT NULL,
            auth_user VARCHAR(255),
            password VARCHAR(255),
            display_name VARCHAR(255),
            transport VARCHAR(20) NOT NULL DEFAULT 'UDP',
            port INT NOT NULL DEFAULT 5060,
            stun_server VARCHAR(255),
            voicemail VARCHAR(255),
            publish_presence TINYINT NOT NULL DEFAULT 0,
            dial_context VARCHAR(255) NOT NULL DEFAULT 'from-internal',
            channel_template VARCHAR(255) NOT NULL DEFAULT 'PJSIP/{extension}',
            caller_id VARCHAR(255),
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS extensions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sip_account_id INT,
            extension VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            secret VARCHAR(255),
            caller_id VARCHAR(255),
            allow_monitoring TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_extensions_sip_account (sip_account_id),
            CONSTRAINT fk_extensions_sip_account FOREIGN KEY(sip_account_id) REFERENCES sip_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sip_account_id INT NOT NULL,
            source_extension_id INT,
            numbers LONGTEXT NOT NULL,
            simultaneous INT NOT NULL DEFAULT 3,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            created_by INT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            INDEX idx_campaigns_sip_account (sip_account_id),
            INDEX idx_campaigns_created_by (created_by),
            INDEX idx_campaigns_source_extension (source_extension_id),
            CONSTRAINT fk_campaigns_sip_account FOREIGN KEY(sip_account_id) REFERENCES sip_accounts(id),
            CONSTRAINT fk_campaigns_source_extension FOREIGN KEY(source_extension_id) REFERENCES extensions(id),
            CONSTRAINT fk_campaigns_created_by FOREIGN KEY(created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS call_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            number VARCHAR(80) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            response LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_call_jobs_campaign_status (campaign_id, status),
            CONSTRAINT fk_call_jobs_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(255) NOT NULL,
            details LONGTEXT,
            ip VARCHAR(64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user (user_id),
            CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','cliente')),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS sip_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            label TEXT,
            sip_server TEXT NOT NULL,
            domain TEXT,
            proxy TEXT,
            username TEXT NOT NULL,
            auth_user TEXT,
            password TEXT,
            display_name TEXT,
            transport TEXT NOT NULL DEFAULT 'UDP',
            port INTEGER NOT NULL DEFAULT 5060,
            stun_server TEXT,
            voicemail TEXT,
            publish_presence INTEGER NOT NULL DEFAULT 0,
            dial_context TEXT NOT NULL DEFAULT 'from-internal',
            channel_template TEXT NOT NULL DEFAULT 'PJSIP/{extension}',
            caller_id TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS extensions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sip_account_id INTEGER,
            extension TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            secret TEXT,
            caller_id TEXT,
            allow_monitoring INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(sip_account_id) REFERENCES sip_accounts(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            sip_account_id INTEGER NOT NULL,
            source_extension_id INTEGER,
            numbers TEXT NOT NULL,
            simultaneous INTEGER NOT NULL DEFAULT 3,
            status TEXT NOT NULL DEFAULT 'draft',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT,
            finished_at TEXT,
            FOREIGN KEY(sip_account_id) REFERENCES sip_accounts(id),
            FOREIGN KEY(source_extension_id) REFERENCES extensions(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS call_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            number TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            response TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            ip TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id)
        );
    ");
    }

    ensure_column('sip_accounts', 'websocket_url', 'TEXT');
    ensure_column('sip_accounts', 'webrtc_enabled', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('sip_accounts', 'ice_servers', "TEXT DEFAULT 'stun:stun.l.google.com:19302'");
    ensure_column('sip_accounts', 'register_expires', 'INTEGER NOT NULL DEFAULT 300');
    ensure_column('sip_accounts', 'dtmf_type', "TEXT NOT NULL DEFAULT 'rtp'");
    ensure_column('sip_accounts', 'user_id', 'INTEGER');
    ensure_column('campaigns', 'dialer_mode', "TEXT NOT NULL DEFAULT 'webrtc'");
    ensure_column('campaigns', 'transfer_target', 'TEXT');
    ensure_column('campaigns', 'agenda_id', 'INTEGER');
    ensure_column('campaigns', 'caller_id', 'TEXT');
    ensure_column('campaigns', 'wait_audio_id', 'INTEGER');
    ensure_column('campaigns', 'start_audio_id', 'INTEGER');
    ensure_column('campaigns', 'queue_type', 'TEXT');
    ensure_column('campaigns', 'rate', 'TEXT');
    ensure_column('campaigns', 'magnus_plan_id', 'INTEGER');
    ensure_column('campaigns', 'magnus_route_id', 'INTEGER');
    ensure_column('campaigns', 'magnus_rate_id', 'INTEGER');
    ensure_column('campaigns', 'magnus_rate_value', 'TEXT');
    ensure_column('campaigns', 'billing_initial_block', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column('campaigns', 'billing_increment', 'INTEGER NOT NULL DEFAULT 6');
    ensure_column('campaigns', 'ddd_intelligent', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('users', 'magnus_user_id', 'INTEGER');
    ensure_column('users', 'magnus_username', 'TEXT');
    ensure_column('users', 'credit_balance', "TEXT NOT NULL DEFAULT '0.00'");
    ensure_column('users', 'credit_consumed', "TEXT NOT NULL DEFAULT '0.00'");
    ensure_column('users', 'default_magnus_rate_id', 'INTEGER');
    ensure_column('users', 'default_magnus_plan_id', 'INTEGER');
    ensure_column('users', 'default_magnus_route_id', 'INTEGER');
    ensure_column('users', 'default_magnus_rate_value', 'TEXT');
    ensure_column('users', 'queue_name', 'TEXT');
    ensure_column('users', 'queue_exten', 'TEXT');
    ensure_column('extensions', 'online_last_seen', 'TEXT');
    ensure_column('extensions', 'user_id', 'INTEGER');
    ensure_column('extensions', 'audio_initial_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('extensions', 'audio_initial_file', 'TEXT');
    ensure_column('extensions', 'audio_transfer_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('extensions', 'audio_transfer_file', 'TEXT');

    if (Database::isMysql()) {
        $db->exec("
        CREATE TABLE IF NOT EXISTS agent_call_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            extension VARCHAR(80) NOT NULL,
            number VARCHAR(80) NOT NULL,
            caller_id VARCHAR(255),
            status VARCHAR(80) NOT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at DATETIME NULL,
            ended_at DATETIME NULL,
            duration_seconds INT NOT NULL DEFAULT 0,
            details LONGTEXT,
            INDEX idx_agent_call_logs_extension (extension, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS auto_agendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            remove_fixed TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS auto_agenda_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agenda_id INT NOT NULL,
            number VARCHAR(80) NOT NULL,
            status VARCHAR(80) NOT NULL DEFAULT 'Pendente',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_auto_agenda_numbers_agenda (agenda_id),
            CONSTRAINT fk_auto_agenda_numbers_agenda FOREIGN KEY(agenda_id) REFERENCES auto_agendas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS auto_audios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            type VARCHAR(80) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS campaign_extensions (
            campaign_id INT NOT NULL,
            extension_id INT NOT NULL,
            PRIMARY KEY(campaign_id, extension_id),
            CONSTRAINT fk_campaign_extensions_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
            CONSTRAINT fk_campaign_extensions_extension FOREIGN KEY(extension_id) REFERENCES extensions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS magnus_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            magnus_plan_id INT NOT NULL,
            magnus_rate_id INT NOT NULL,
            magnus_route_id INT NOT NULL,
            plan_name VARCHAR(255) NOT NULL,
            route_name VARCHAR(255) NOT NULL,
            rate_value VARCHAR(80) NOT NULL,
            destination VARCHAR(255),
            active TINYINT NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_magnus_rate (magnus_plan_id, magnus_rate_id, magnus_route_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS ivrs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(40) NOT NULL DEFAULT 'normal',
            name VARCHAR(255) NOT NULL,
            description LONGTEXT,
            audio_id INT NULL,
            timeout_seconds INT NOT NULL DEFAULT 10,
            max_attempts INT NOT NULL DEFAULT 2,
            invalid_destination VARCHAR(255),
            timeout_destination VARCHAR(255),
            default_destination VARCHAR(255),
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_ivrs_mode_active (mode, active),
            CONSTRAINT fk_ivrs_audio FOREIGN KEY(audio_id) REFERENCES auto_audios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS ivr_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ivr_id INT NOT NULL,
            digit VARCHAR(20) NOT NULL,
            label VARCHAR(255) NOT NULL,
            destination_type VARCHAR(40) NOT NULL DEFAULT 'fila',
            destination VARCHAR(255) NOT NULL,
            priority_order INT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            CONSTRAINT fk_ivr_options_ivr FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_ivr_digit (ivr_id, digit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS call_queues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            queue_number VARCHAR(40) NOT NULL UNIQUE,
            asterisk_name VARCHAR(120) NOT NULL UNIQUE,
            strategy VARCHAR(40) NOT NULL DEFAULT 'ringall',
            timeout_seconds INT NOT NULL DEFAULT 30,
            max_wait_seconds INT NOT NULL DEFAULT 300,
            max_calls INT NOT NULL DEFAULT 0,
            wrapup_seconds INT NOT NULL DEFAULT 0,
            max_no_agent_seconds INT NOT NULL DEFAULT 90,
            short_talk_seconds INT NOT NULL DEFAULT 120,
            join_empty TINYINT NOT NULL DEFAULT 1,
            record_calls TINYINT NOT NULL DEFAULT 0,
            musicclass VARCHAR(120) NOT NULL DEFAULT 'default',
            announce_position TINYINT NOT NULL DEFAULT 0,
            announce_frequency INT NOT NULL DEFAULT 30,
            exit_digit VARCHAR(20),
            overflow_type VARCHAR(40) NOT NULL DEFAULT 'hangup',
            overflow_destination VARCHAR(255),
            tier_rules_enabled TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            description LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_call_queues_user (user_id),
            CONSTRAINT fk_call_queues_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS call_queue_extensions (
            queue_id INT NOT NULL,
            extension_id INT NOT NULL,
            penalty INT NOT NULL DEFAULT 0,
            PRIMARY KEY(queue_id, extension_id),
            CONSTRAINT fk_call_queue_extensions_queue FOREIGN KEY(queue_id) REFERENCES call_queues(id) ON DELETE CASCADE,
            CONSTRAINT fk_call_queue_extensions_extension FOREIGN KEY(extension_id) REFERENCES extensions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $db->exec("
        CREATE TABLE IF NOT EXISTS agent_call_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            extension TEXT NOT NULL,
            number TEXT NOT NULL,
            caller_id TEXT,
            status TEXT NOT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at TEXT,
            ended_at TEXT,
            duration_seconds INTEGER NOT NULL DEFAULT 0,
            details TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_agent_call_logs_extension ON agent_call_logs(extension, started_at DESC);
        CREATE TABLE IF NOT EXISTS auto_agendas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            remove_fixed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS auto_agenda_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agenda_id INTEGER NOT NULL,
            number TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pendente',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(agenda_id) REFERENCES auto_agendas(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_auto_agenda_numbers_agenda ON auto_agenda_numbers(agenda_id);
        CREATE TABLE IF NOT EXISTS auto_audios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            type TEXT NOT NULL,
            file_name TEXT NOT NULL,
            file_path TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS campaign_extensions (
            campaign_id INTEGER NOT NULL,
            extension_id INTEGER NOT NULL,
            PRIMARY KEY(campaign_id, extension_id),
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
            FOREIGN KEY(extension_id) REFERENCES extensions(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS magnus_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            magnus_plan_id INTEGER NOT NULL,
            magnus_rate_id INTEGER NOT NULL,
            magnus_route_id INTEGER NOT NULL,
            plan_name TEXT NOT NULL,
            route_name TEXT NOT NULL,
            rate_value TEXT NOT NULL,
            destination TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            UNIQUE(magnus_plan_id, magnus_rate_id, magnus_route_id)
        );
        CREATE TABLE IF NOT EXISTS ivrs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mode TEXT NOT NULL DEFAULT 'normal',
            name TEXT NOT NULL,
            description TEXT,
            audio_id INTEGER,
            timeout_seconds INTEGER NOT NULL DEFAULT 10,
            max_attempts INTEGER NOT NULL DEFAULT 2,
            invalid_destination TEXT,
            timeout_destination TEXT,
            default_destination TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(audio_id) REFERENCES auto_audios(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ivrs_mode_active ON ivrs(mode, active);
        CREATE TABLE IF NOT EXISTS ivr_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ivr_id INTEGER NOT NULL,
            digit TEXT NOT NULL,
            label TEXT NOT NULL,
            destination_type TEXT NOT NULL DEFAULT 'fila',
            destination TEXT NOT NULL,
            priority_order INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            UNIQUE(ivr_id, digit),
            FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS call_queues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            queue_number TEXT NOT NULL UNIQUE,
            asterisk_name TEXT NOT NULL UNIQUE,
            strategy TEXT NOT NULL DEFAULT 'ringall',
            timeout_seconds INTEGER NOT NULL DEFAULT 30,
            max_wait_seconds INTEGER NOT NULL DEFAULT 300,
            max_calls INTEGER NOT NULL DEFAULT 0,
            wrapup_seconds INTEGER NOT NULL DEFAULT 0,
            max_no_agent_seconds INTEGER NOT NULL DEFAULT 90,
            short_talk_seconds INTEGER NOT NULL DEFAULT 120,
            join_empty INTEGER NOT NULL DEFAULT 1,
            record_calls INTEGER NOT NULL DEFAULT 0,
            musicclass TEXT NOT NULL DEFAULT 'default',
            announce_position INTEGER NOT NULL DEFAULT 0,
            announce_frequency INTEGER NOT NULL DEFAULT 30,
            exit_digit TEXT,
            overflow_type TEXT NOT NULL DEFAULT 'hangup',
            overflow_destination TEXT,
            tier_rules_enabled INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            description TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_call_queues_user ON call_queues(user_id);
        CREATE TABLE IF NOT EXISTS call_queue_extensions (
            queue_id INTEGER NOT NULL,
            extension_id INTEGER NOT NULL,
            penalty INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(queue_id, extension_id),
            FOREIGN KEY(queue_id) REFERENCES call_queues(id) ON DELETE CASCADE,
            FOREIGN KEY(extension_id) REFERENCES extensions(id) ON DELETE CASCADE
        );
    ");
    }
    ensure_column('agent_call_logs', 'caller_id', 'TEXT');
    ensure_column('auto_agendas', 'user_id', 'INTEGER');
    ensure_column('auto_agenda_numbers', 'cpf', 'TEXT');
    ensure_column('auto_agenda_numbers', 'name', 'TEXT');
    ensure_column('auto_audios', 'user_id', 'INTEGER');
    $db->exec('UPDATE sip_accounts SET user_id=1 WHERE user_id IS NULL');
    $db->exec('UPDATE auto_agendas SET user_id=1 WHERE user_id IS NULL');
    $db->exec('UPDATE auto_audios SET user_id=1 WHERE user_id IS NULL');
    ensure_column('call_jobs', 'answered_at', 'TEXT');
    ensure_column('call_jobs', 'ended_at', 'TEXT');
    ensure_column('call_jobs', 'duration_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_jobs', 'billed_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_jobs', 'cost', "TEXT NOT NULL DEFAULT '0.00'");
    ensure_column('call_jobs', 'charged_at', 'TEXT');
    ensure_column('call_jobs', 'agenda_number_id', 'INTEGER');
    ensure_column('agent_call_logs', 'user_id', 'INTEGER');
    ensure_column('agent_call_logs', 'magnus_rate_id', 'INTEGER');
    ensure_column('agent_call_logs', 'magnus_rate_value', "TEXT");
    ensure_column('agent_call_logs', 'billing_initial_block', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column('agent_call_logs', 'billing_increment', 'INTEGER NOT NULL DEFAULT 6');
    ensure_column('agent_call_logs', 'billed_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('agent_call_logs', 'cost', "TEXT NOT NULL DEFAULT '0.00'");
    ensure_column('agent_call_logs', 'charged_at', 'TEXT');
    ensure_column('ivrs', 'sip_account_id', 'INTEGER');
    ensure_column('ivrs', 'source_extension_id', 'INTEGER');
    ensure_column('ivrs', 'agenda_id', 'INTEGER');
    ensure_column('ivrs', 'test_phone', 'TEXT');
    ensure_column('ivrs', 'caller_id', 'TEXT');
    ensure_column('ivrs', 'transfer_target', 'TEXT');
    ensure_column('ivrs', 'simultaneous', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('ivrs', 'play_audio_on_answer', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('ivrs', 'show_digit_viewer', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('call_queues', 'user_id', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('call_queues', 'name', 'TEXT');
    ensure_column('call_queues', 'queue_number', 'TEXT');
    ensure_column('call_queues', 'asterisk_name', 'TEXT');
    ensure_column('call_queues', 'strategy', "TEXT NOT NULL DEFAULT 'ringall'");
    ensure_column('call_queues', 'timeout_seconds', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column('call_queues', 'max_wait_seconds', 'INTEGER NOT NULL DEFAULT 300');
    ensure_column('call_queues', 'max_calls', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_queues', 'wrapup_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_queues', 'max_no_agent_seconds', 'INTEGER NOT NULL DEFAULT 90');
    ensure_column('call_queues', 'short_talk_seconds', 'INTEGER NOT NULL DEFAULT 120');
    ensure_column('call_queues', 'join_empty', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('call_queues', 'record_calls', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_queues', 'musicclass', "TEXT NOT NULL DEFAULT 'default'");
    ensure_column('call_queues', 'announce_position', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_queues', 'announce_frequency', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column('call_queues', 'exit_digit', 'TEXT');
    ensure_column('call_queues', 'overflow_type', "TEXT NOT NULL DEFAULT 'hangup'");
    ensure_column('call_queues', 'overflow_destination', 'TEXT');
    ensure_column('call_queues', 'tier_rules_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column('call_queues', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column('call_queues', 'description', 'TEXT');
    ensure_column('call_queues', 'updated_at', 'TEXT');
    ensure_column('magnus_rates', 'initial_block_seconds', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column('magnus_rates', 'billing_increment_seconds', 'INTEGER NOT NULL DEFAULT 6');

    if (Database::isMysql()) {
        $db->exec("
        CREATE TABLE IF NOT EXISTS ivr_digit_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ivr_id INT NULL,
            call_id VARCHAR(255),
            phone VARCHAR(80),
            digit VARCHAR(20) NOT NULL,
            matched_option_id INT NULL,
            destination VARCHAR(255),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ivr_digit_events_ivr_date (ivr_id, created_at),
            CONSTRAINT fk_ivr_digit_events_ivr FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE SET NULL,
            CONSTRAINT fk_ivr_digit_events_option FOREIGN KEY(matched_option_id) REFERENCES ivr_options(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS ivr_call_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ivr_id INT NULL,
            mode VARCHAR(40),
            phone VARCHAR(80),
            caller_id VARCHAR(80),
            status VARCHAR(80) NOT NULL,
            message LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ivr_call_events_ivr_date (ivr_id, created_at),
            CONSTRAINT fk_ivr_call_events_ivr FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $db->exec("
        CREATE TABLE IF NOT EXISTS ivr_digit_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ivr_id INTEGER,
            call_id TEXT,
            phone TEXT,
            digit TEXT NOT NULL,
            matched_option_id INTEGER,
            destination TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE SET NULL,
            FOREIGN KEY(matched_option_id) REFERENCES ivr_options(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ivr_digit_events_ivr_date ON ivr_digit_events(ivr_id, created_at DESC);
        CREATE TABLE IF NOT EXISTS ivr_call_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ivr_id INTEGER,
            mode TEXT,
            phone TEXT,
            caller_id TEXT,
            status TEXT NOT NULL,
            message TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(ivr_id) REFERENCES ivrs(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ivr_call_events_ivr_date ON ivr_call_events(ivr_id, created_at DESC);
        ");
    }

    $rates = [
        [1, 1, 1, 'Rota-aberta010', 'Rota-aberta', '0.100000'],
        [2, 2, 1, 'Rota-aberta015', 'Rota-aberta', '0.150000'],
        [3, 3, 1, 'Rota-aberta025', 'Rota-aberta', '0.250000'],
        [4, 4, 1, 'Rota-aberta035', 'Rota-aberta', '0.350000'],
        [5, 5, 1, 'Rota-aberta050', 'Rota-aberta', '0.500000'],
        [6, 13, 2, 'Rota-mista08', 'Rota-mista', '0.080000'],
        [7, 10, 2, 'Rota-mista015', 'Rota-mista', '0.150000'],
        [8, 11, 2, 'Rota-mista025', 'Rota-mista', '0.250000'],
        [9, 12, 2, 'Rota-mista035', 'Rota-mista', '0.350000'],
        [10, 9, 3, 'Rota-aberta-dois-09', 'Rota-aberta-dois', '0.090000'],
        [11, 6, 3, 'Rota-aberta-dois-custo', 'Rota-aberta-dois', '0.150000'],
        [12, 7, 3, 'Rota-aberta-dois-025', 'Rota-aberta-dois', '0.250000'],
        [13, 8, 3, 'Rota-aberta-dois-035', 'Rota-aberta-dois', '0.350000'],
        [14, 14, 4, 'Rota-Mexicana-015', 'Rota-Mexicana', '0.150000'],
        [15, 15, 4, 'Rota-Mexicana-040', 'Rota-Mexicana', '0.400000'],
        [16, 16, 4, 'Rota-Mexicana-050', 'Rota-Mexicana', '0.500000'],
        [17, 17, 4, 'Rota-Mexicana-060', 'Rota-Mexicana', '0.600000'],
        [18, 18, 1, 'asmo-Rota-aberta050', 'Rota-aberta', '0.500000'],
    ];
    $insertRateSql = Database::isMysql()
        ? 'INSERT IGNORE INTO magnus_rates(magnus_plan_id, magnus_rate_id, magnus_route_id, plan_name, route_name, rate_value, initial_block_seconds, billing_increment_seconds) VALUES(?,?,?,?,?,?,30,6)'
        : 'INSERT OR IGNORE INTO magnus_rates(magnus_plan_id, magnus_rate_id, magnus_route_id, plan_name, route_name, rate_value, initial_block_seconds, billing_increment_seconds) VALUES(?,?,?,?,?,?,30,6)';
    $insertRate = $db->prepare($insertRateSql);
    foreach ($rates as $rate) {
        $insertRate->execute($rate);
    }

    if (Database::isMysql()) {
        $db->exec("
        CREATE TABLE IF NOT EXISTS credit_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount VARCHAR(80) NOT NULL,
            balance_after VARCHAR(80) NOT NULL,
            type VARCHAR(40) NOT NULL,
            note LONGTEXT,
            created_by INT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_credit_transactions_user_date (user_id, created_at),
            CONSTRAINT fk_credit_transactions_user FOREIGN KEY(user_id) REFERENCES users(id),
            CONSTRAINT fk_credit_transactions_admin FOREIGN KEY(created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $db->exec("
        CREATE TABLE IF NOT EXISTS call_billing_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(40) NOT NULL,
            source_id INT NOT NULL,
            user_id INT NOT NULL,
            rate_id INT NULL,
            rate_value VARCHAR(80) NOT NULL,
            initial_block_seconds INT NOT NULL DEFAULT 30,
            billing_increment_seconds INT NOT NULL DEFAULT 6,
            answered_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            duration_seconds INT NOT NULL DEFAULT 0,
            billed_seconds INT NOT NULL DEFAULT 0,
            charged_cents INT NOT NULL DEFAULT 0,
            finalized TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_call_billing_source (source_type, source_id),
            INDEX idx_call_billing_user_date (user_id, created_at),
            CONSTRAINT fk_call_billing_user FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $db->exec("
        CREATE TABLE IF NOT EXISTS credit_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount TEXT NOT NULL,
            balance_after TEXT NOT NULL,
            type TEXT NOT NULL,
            note TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE INDEX IF NOT EXISTS idx_credit_transactions_user_date ON credit_transactions(user_id, created_at DESC);
        CREATE TABLE IF NOT EXISTS call_billing_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type TEXT NOT NULL,
            source_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            rate_id INTEGER,
            rate_value TEXT NOT NULL,
            initial_block_seconds INTEGER NOT NULL DEFAULT 30,
            billing_increment_seconds INTEGER NOT NULL DEFAULT 6,
            answered_at TEXT NOT NULL,
            ended_at TEXT,
            duration_seconds INTEGER NOT NULL DEFAULT 0,
            billed_seconds INTEGER NOT NULL DEFAULT 0,
            charged_cents INTEGER NOT NULL DEFAULT 0,
            finalized INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            UNIQUE(source_type, source_id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        );
        CREATE INDEX IF NOT EXISTS idx_call_billing_user_date ON call_billing_records(user_id, created_at DESC);
    ");
    }

    $stmt = $db->query('SELECT COUNT(*) FROM users');
    if ((int)$stmt->fetchColumn() === 0) {
        $admin = $config['default_admin'];
        $insert = $db->prepare('INSERT INTO users(name, email, password_hash, role) VALUES(?, ?, ?, ?)');
        $insert->execute([
            $admin['name'],
            $admin['email'],
            password_hash($admin['password'], PASSWORD_DEFAULT),
            'admin',
        ]);
    }

    $defaults = [
        'ami_host' => '127.0.0.1',
        'ami_port' => '5038',
        'ami_user' => '',
        'ami_secret' => '',
        'ami_timeout' => '5',
        'monitoring_enabled' => '0',
        'monitoring_notice' => 'Monitoramento permitido somente com consentimento, finalidade administrativa e auditoria.',
        'app_public_url' => '',
        'asterisk_ivr_event_secret' => '',
        'asterisk_sync_enabled' => '1',
        'asterisk_sync_host' => '',
        'asterisk_sync_user' => 'root',
        'asterisk_sync_key' => __DIR__ . '/../data/asterisk_sync_ed25519',
        'asterisk_sync_external_ip' => '',
        'asterisk_sync_queue' => 'discadora',
        'asterisk_sync_queue_exten' => '700',
        'magnus_sync_enabled' => '1',
        'magnus_sync_host' => '',
        'magnus_sync_user' => 'root',
        'magnus_sync_key' => __DIR__ . '/../data/magnus_sync_ed25519',
        'magnus_template_user_id' => '33',
        'brand_name' => 'Discadora SIP',
        'brand_logo' => '',
        'brand_primary' => '#16745f',
        'brand_accent' => '#d68a1f',
        'default_theme' => 'light',
        'google_recaptcha_enabled' => '0',
        'google_recaptcha_site_key' => '',
        'google_recaptcha_secret_key' => '',
        'system_block_enabled' => '0',
        'system_block_message' => 'Acesso temporariamente bloqueado. Fale com o administrador.',
        'maintenance_enabled' => '0',
        'maintenance_message' => 'Manutencao programada em andamento. Voltaremos em breve.',
    ];
    foreach ($defaults as $key => $value) {
        $stmt = $db->prepare(Database::isMysql() ? 'INSERT IGNORE INTO settings(`key`, value) VALUES(?, ?)' : 'INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
        $stmt->execute([$key, $value]);
    }

    foreach ($db->query("SELECT id, email, role FROM users WHERE queue_name IS NULL OR queue_name='' OR queue_exten IS NULL OR queue_exten=''") as $row) {
        $queueName = default_queue_name_for_user((int)$row['id'], (string)$row['email']);
        $queueExten = default_queue_exten_for_user((int)$row['id']);
        $stmt = $db->prepare('UPDATE users SET queue_name=COALESCE(NULLIF(queue_name, \'\'), ?), queue_exten=COALESCE(NULLIF(queue_exten, \'\'), ?) WHERE id=?');
        $stmt->execute([$queueName, $queueExten, (int)$row['id']]);
    }
    $db->exec("UPDATE extensions SET user_id=1 WHERE user_id IS NULL");
}

function ensure_column(string $table, string $column, string $definition): void
{
    $db = Database::conn();
    if (Database::isMysql()) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` " . mysql_column_definition($definition));
        return;
    }
    $columns = $db->query("PRAGMA table_info({$table})")->fetchAll();
    foreach ($columns as $existing) {
        if ($existing['name'] === $column) return;
    }
    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function mysql_column_definition(string $definition): string
{
    $definition = trim($definition);
    if (preg_match('/^TEXT\b(.*)$/i', $definition, $matches)) {
        $suffix = trim($matches[1]);
        $definition = stripos($suffix, 'DEFAULT') !== false ? 'VARCHAR(1000) ' . $suffix : 'LONGTEXT ' . $suffix;
    }
    $definition = preg_replace('/\bINTEGER\b/i', 'INT', $definition, 1);
    return $definition;
}

function default_queue_name_for_user(int $id, string $seed = ''): string
{
    $base = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', preg_replace('/@.*/', '', $seed) ?: 'user'));
    $base = $base !== '' ? $base : 'user';
    return 'discadora_' . substr($base, 0, 14) . '_' . $id;
}

function default_queue_exten_for_user(int $id): string
{
    return (string)(700 + $id);
}
