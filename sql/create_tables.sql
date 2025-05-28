CREATE TABLE `appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `time_id` int NOT NULL,
  `name` varchar(255) DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
);

CREATE TABLE `terms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `start` date NOT NULL,
  `end` date NOT NULL,
  `slots` int DEFAULT '0',
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `times` (
  `id` int NOT NULL AUTO_INCREMENT,
  `time` datetime NOT NULL,
  `available` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
);
