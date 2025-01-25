DELETE FROM applications;
DELETE FROM users;
DELETE FROM recydywa;
DELETE FROM webhooks;
DELETE FROM queue;
DELETE FROM sqlite_sequence;

INSERT INTO users VALUES('e@nieradka.net', '{"added":"2021-04-26T19:54:57","data":{"email":"e@nieradka.net","name":"Tester Automatyczny","exposeData":false,"sex":"m","msisdn":"","address":"Mazurska 37, Szczecin"},"updated":"2021-04-26T19:54:57","number":4,"appsCount":0}');
