// D1 mini Pro v2 m lipobort
//SHT30 på i2cport
// vattenivåsensor, ej klar
//Soilsensor med 2 Dig GPIO
//batterimätning
// CFG, SQL
//T, H, Bat, 


#include <Wire.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiManager.h>
#include <ArduinoJson.h>
#include <Adafruit_SHT31.h>


// --- Sensorobjekt
Adafruit_SHT31 sht31 = Adafruit_SHT31();

// --- Konfiguration
const char* serverName       = "https://monosense.se/insertdata.php";
String apiKeyValue           = "xxxxx";
String sensorName            = "D1miniPro-SHT30";
const String firmwareVersion = "1.1";

String hostname;
String sensorLocation;
int intervalMinutes = 60;
float fVoltage = 0;
float fmVoltage = 0;
int perc = 0;

// Konfiguration soilsensor
const int GPIO_A = 12; // Probe1
const int GPIO_B = 13; // probe2
const unsigned int CHARGE_DELAY_MS = 5; //Hur länge sensorn laddas (i millisekunder) innan urladdningstid mäts. Påverkar laddningsdjup.
const unsigned int TIMEOUT_US = 3000; //Hur länge vi väntar (i mikrosekunder) innan vi avbryter mätningen om urladdning inte sker. Skyddar mot låsning.
const int SAMPLES = 5; //Antal dubbelmätningar (växlad polaritet) som tas och medelvärdesberäknas för ökad noggrannhet. Varje iteration gör två mätningar.

unsigned long soilDischargeTime = 0;


void getconfig() {
  String mac = WiFi.macAddress();
  mac.replace(":", "");
  String url = "https://monosense.se/get_params.php?sensor_mac=" + mac;
  Serial.println("Hämtar konfiguration från: " + url);

  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient https;
  if (https.begin(client, url)) {
    int httpCode = https.GET();
    if (httpCode == 200) {
      String payload = https.getString();
      Serial.println("Svar från server: " + payload);

      DynamicJsonDocument doc(512);
      auto error = deserializeJson(doc, payload);
      if (!error && doc.containsKey("cfg_interval")) {
        intervalMinutes = doc["cfg_interval"].as<int>();
        if (intervalMinutes < 1 || intervalMinutes > 1440) {
          Serial.println("Ogiltigt cfg_interval, använder 60");
          intervalMinutes = 60;
        }
      } else {
        Serial.println("JSON-fel eller cfg_interval saknas, använder 60");
        intervalMinutes = 60;
      }
    } else {
      Serial.print("HTTP-fel: "); Serial.println(httpCode);
      intervalMinutes = 60;
    }
    https.end();
  } else {
    Serial.println("Kunde inte ansluta till servern.");
    intervalMinutes = 60;
  }
  Serial.printf("Konfiguration: interval=%d min\n", intervalMinutes);
}

void readBattery(){
  //calculate battery level, A0 pin, Lipo
  
  int nVoltageRaw = analogRead(A0);
  Serial.print("A0 raw value: ");
  Serial.println(nVoltageRaw);
  fVoltage = (float)nVoltageRaw * 0.004146 ;
  fmVoltage = fVoltage * 1000;

  if (fVoltage >= 4.10)
    perc = 100;
  else if (fVoltage >= 4.00)
    perc = 85;
  else if (fVoltage >= 3.90)
    perc = 70;
  else if (fVoltage >= 3.80)
    perc = 55;
  else if (fVoltage >= 3.70)
    perc = 40;
  else if (fVoltage >= 3.60)
    perc = 25;
  else if (fVoltage >= 3.50)
    perc = 10;
  else
    perc = 0;
 
}

void readWaterLevel() {
  
}

void measureSoilMoisture() {
  auto measureRC = [&](int chargePin, int readPin) -> unsigned long {
    pinMode(chargePin, OUTPUT);
    digitalWrite(chargePin, HIGH);

    pinMode(readPin, OUTPUT);
    digitalWrite(readPin, LOW);
    delay(CHARGE_DELAY_MS);

    pinMode(readPin, INPUT);
    digitalWrite(chargePin, LOW);
    pinMode(chargePin, INPUT);

    unsigned long start = micros();
    while (digitalRead(readPin) == HIGH) {
      if (micros() - start > TIMEOUT_US) break;
    }
    return micros() - start;
  };

  unsigned long total = 0;
  for (int i = 0; i < SAMPLES; i++) {
    total += measureRC(GPIO_A, GPIO_B);
    delay(5);
    total += measureRC(GPIO_B, GPIO_A);
    delay(5);
  }

  soilDischargeTime = total / (2 * SAMPLES);
}



void setup() {
  Serial.begin(115200);
  Serial.println("\n--- Monosense D1miniPro SHT30 ---");
  Serial.printf("Firmware v%s\n", firmwareVersion.c_str());
   WiFi.persistent(true);
  WiFi.mode(WIFI_STA);
  system_deep_sleep_set_option(1);  // 1 = spara RF‐calibration och undvik omkalibrering



  
  // I2C på D2=D4(GPIO4)=SDA, D1=D5(GPIO5)=SCL
  Wire.begin(4, 5);
  delay(100);
  if (!sht31.begin(0x45)) {
    Serial.println("SHT31/SHT30 hittades inte.");
    while (true) { delay(1000); }
  }
  Serial.println("Sensoe SHT31/SHT30 initierad");

  // WiFiManager + hostname från MAC
  String macRaw = WiFi.macAddress();
  String macClean = macRaw; macClean.replace(":", "");
  String suffix = macClean.substring(macClean.length() - 4);
  hostname = "Monosense-" + suffix;

 

  WiFiManager wm;
  if (!wm.autoConnect(hostname.c_str())) {
    Serial.println("Misslyckades ansluta till wifi - startar om...");
    delay(3000);
    ESP.restart();
  }
 
  sensorLocation = macClean;

  delay(500);
 
  getconfig();
}

void loop() {
  // Läs sensor
  float tempC = sht31.readTemperature();
  float humi  = sht31.readHumidity();



  if (isnan(tempC) || isnan(humi)) {
    Serial.println("Misslyckades läsa från SHT30!");
  } else {
    Serial.printf("Temp: %.2f °C\tRH: %.2f %%\n", tempC, humi);
  }



  readBattery(); 

  // RSSI -> kvalitet
  long rssi = WiFi.RSSI();
  int quality = (rssi <= -100) ? 0 :
                (rssi >= -50)  ? 100 :
                2 * (rssi + 100);

  // Skicka till server
  if (WiFi.status() == WL_CONNECTED) {
    WiFiClientSecure client;
    client.setInsecure();
    HTTPClient https;
    if (https.begin(client, serverName)) {
      https.addHeader("Content-Type", "application/x-www-form-urlencoded");
      String postData = "api_key=" + apiKeyValue +
                        "&sensor_type=" + sensorName +
                        "&sensor_mac="  + sensorLocation +
                        "&value1="      + String(tempC, 2) +
                        "&value2="      + String(humi, 2) +
                        "&value5="      + String(quality) +
                        "&value4=" + String(perc) +
                        "&firmware_version=" + firmwareVersion +
                        "&value8=" + String(fmVoltage, 2) +
                        "";
      Serial.println("Skickar data: " + postData);
      int code = https.POST(postData);
      Serial.printf("HTTP Response: %d\n", code);
      https.end();
    } else {
      Serial.println("Kunde inte initiera HTTPClient.");
    }
  } else {
    Serial.println("WiFi ej ansluten.");
  }




sht31.heater(false);

pinMode(LED_BUILTIN, OUTPUT);
digitalWrite(LED_BUILTIN, HIGH);  // D1 Mini-LED är active-LOW → HIGH = av

Serial.printf("Går i djupsömn i %d minuter...\n", intervalMinutes);
uint64_t sleepTime = (uint64_t)intervalMinutes * 60ULL * 1000000ULL;
ESP.deepSleep(sleepTime);

}
