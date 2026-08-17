CREATE TABLE IF NOT EXISTS chatbot_knowledge (
    knowledge_id INT PRIMARY KEY AUTO_INCREMENT,
    topic VARCHAR(120) NOT NULL,
    keywords TEXT NULL,
    answer TEXT NOT NULL,
    steps TEXT NULL,
    category VARCHAR(80) DEFAULT 'general',
    source VARCHAR(255) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chatbot_knowledge_status (status),
    INDEX idx_chatbot_knowledge_category (category),
    FULLTEXT KEY ft_chatbot_knowledge (topic, keywords, answer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
