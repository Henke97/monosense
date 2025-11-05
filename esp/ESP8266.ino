// USB stick med DHT11/22

// ESP 8266
//DHT11 till pin2
//Sensor pin GPIO2, mysql upload, CFG 1 parametrar, VM
// Optimerad i Grok


#include <ESP8266WiFi.h>
#include <ESPAsyncTCP.h>
#include <ESP8266HTTPClient.h>
#include <DHT.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>

const char* serverName = "https://monosense.se/insertdata.php";
const char* apiKeyValue = "xxxxx";
const char* sensorName = "8266-DHT11";
char sensorLocation[20];
const char* power = "USB";
const char* firmwareVersion = "1.0";
int intervalMinutes = 60;

#define DHTPIN 2
#define DHTTYPE DHT11
DHT dht(DHTPIN, DHTTYPE);

WiFiClientSecure client;

void reconnectWiFi() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Försöker återansluta till WiFi...");
    WiFi.reconnect();
    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 10000) {
      delay(500);
      Serial.print(".");
    }
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("!!Återanslutning misslyckades.");
    } else {
      Serial.println("WiFi återansluten.");
    }
  }
}

void getconfig() {
  String mac = WiFi.macAddress();
  mac.replace(":", "");
  String url = "https://monosense.se/get_params.php?sensor_mac=" + mac;

  Serial.println("Hämtar konfiguration från: " + url);

  client.setInsecure();
  HTTPClient https;
  https.setTimeout(5000);
  if (https.begin(client, url)) {
    int httpCode = https.GET();
    if (httpCode == 200) {
      String payload = https.getString();
      Serial.println("Svar från server: " + payload);

      StaticJsonDocument<256> doc;
      DeserializationError error = deserializeJson(doc, payload);
      if (!error) {
        if (doc.containsKey("cfg_interval")) {
          intervalMinutes = doc["cfg_interval"].as<int>();
          if (intervalMinutes < 1 || intervalMinutes > 1440) {
            Serial.println("Ogiltigt cfg_interval, använder 60");
            intervalMinutes = 60;
          }
        }
      } else {
        Serial.print("JSON-fel: ");
        Serial.println(error.c_str());
        intervalMinutes = 60;
      }
    } else {
      Serial.printf("HTTP-fel: %d\n", httpCode);
      intervalMinutes = 60;
    }
    https.end();
  } else {
    Serial.println("!!Kunde inte ansluta till servern.");
    intervalMinutes = 60;
  }

  Serial.printf("Konfiguration: interval=%d min, sensor=%s\n", intervalMinutes, sensorName);
}

void setup() {
  Serial.begin(115200);
  ESP.wdtEnable(8000); // Aktivera watchdog

  String macRaw = WiFi.macAddress();
  macRaw.replace(":", "");
  strncpy(sensorLocation, macRaw.c_str(), sizeof(sensorLocation));
  String suffix = macRaw.substring(8, 12);
  String hostname = "Monosense-" + suffix;
  Serial.println("Hostname: " + hostname);
  WiFi.hostname(hostname.c_str());

  WiFiManager wm;
  if (!wm.autoConnect(hostname.c_str())) {
    Serial.println("WiFi-anslutning misslyckades. Försöker igen om 3 sek...");
    delay(3000);
    ESP.restart(); // Behåll omstart här för att hantera WiFi-fel i setup
  }

  Serial.printf("Startar Monosense Firmware v%s\n", firmwareVersion);
  getconfig();
  dht.begin();
}

void loop() {
  ESP.wdtFeed(); // Återställ watchdog

  delay(2000); // Vänta för DHT-stabilitet
  float humi = dht.readHumidity();
  float tempC = dht.readTemperature();
  float tempF = dht.readTemperature(true);
  float calibrationOffset = (DHTTYPE == DHT11) ? 4.0 : 2.0;
  tempC -= calibrationOffset;

  if (isnan(humi) || isnan(tempC) || isnan(tempF)) {
    Serial.println("!!Misslyckades läsa från DHT-sensorn.");
  } else {
    Serial.printf("Humidity: %.1f%%  |  Temperature: %.1f°C\n", humi, tempC);
  }

  long rssi = WiFi.RSSI();
  int quality = 0;
  if (rssi <= -100) quality = 0;
  else if (rssi >= -50) quality = 100;
  else quality = 2 * (rssi + 100);

  reconnectWiFi();
  if (WiFi.status() == WL_CONNECTED) {
    client.setInsecure();
    HTTPClient https;
    https.setTimeout(5000);
    https.begin(client, serverName);
    https.addHeader("Content-Type", "application/x-www-form-urlencoded");

    char httpRequestData[256];
    snprintf(httpRequestData, sizeof(httpRequestData),
             "api_key=%s&sensor_type=%s&sensor_mac=%s&value1=%.2f&value2=%.2f&value4=%s&firmware_version=%s&sensor_update=%d&value5=%d",
             apiKeyValue, sensorName, sensorLocation, tempC, humi, power, firmwareVersion, intervalMinutes, quality);

    Serial.println("Skickar data: " + String(httpRequestData));
    int httpResponseCode = https.POST(httpRequestData);

    if (httpResponseCode > 0) {
      Serial.printf("Svar från server: %d\n", httpResponseCode);
    } else {
      Serial.printf("HTTP-fel: %d\n", httpResponseCode);
    }
    https.end();
  } else {
    Serial.println("!!WiFi inte ansluten.");
  }

  Serial.printf("Ledigt heap: %d byte\n", ESP.getFreeHeap());
  if (ESP.getFreeHeap() < 10000) {
    Serial.println("Varning: Lågt minne, överväger omstart...");
    ESP.restart(); // Omstart vid lågt minne
  }

  Serial.printf("Paus i %d minuter\n", intervalMinutes);
  unsigned long waitTime = intervalMinutes * 60UL * 1000UL;
  unsigned long waitStart = millis();
  while (millis() - waitStart < waitTime) {
    delay(100); // Mindre delay för att ge watchdog tid
    ESP.wdtFeed(); // Återställ watchdog under väntan
  }

  Serial.println("Paus klar, nästa mätning...");
}
