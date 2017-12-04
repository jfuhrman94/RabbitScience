#include <Bridge.h>

// Start a process instance
Process process;

// Set up values
int state = 0;
const int buff = 10;
char data[buff];

void setup() {
  // Bridge startup
  pinMode(13, OUTPUT);
  Serial.begin(9600);
  digitalWrite(13, LOW);
  Bridge.begin();
  digitalWrite(13, HIGH);

  // Wait for Yun to respond
  wait_state();

  // Load the most recently saved state
  update_state();
  Bridge.put("DATA_TIMESTAMP", "0");
  Bridge.put("ARDUINO_STATUS", "RUNNING");
  digitalWrite(13, LOW);
}

// Main state loop
void loop() {
  Serial.println("Entering State:");
  if (state == 0) {
    state_00();
  } else if (state == 99) {
    state_99();
  } else {
    Serial.println("Unknown State");
    state_00();
  }

}

// Update state function
void update_state() {
  Serial.println("Updating state...");
  Bridge.get("STATE_ID", data, 10);
  state = String(data).toInt();
  Bridge.put("UPDATE", "False");
  Serial.println("Updated state to " + String(state));
}

// wait for Yun to post desired state
void wait_state() {
  Serial.println("Waiting on Yun...");
  Bridge.put("ARDUINO_STATUS", "LOADING");
  while (true) {
    Bridge.get("UPDATE", data, buff);
    if (String(data) == "True") {
      break;
    } else {
      delay(50);
    }
  }
  Bridge.put("ARDUINO_STATUS", "RUNNING");
}

// Get unix timestamp from Yun as string
String get_timestamp() {
  String timestamp;
  if (!process.running()) {
    process.begin("date");
    process.addParameter("+%s");
    process.run();
  }
  while (process.available() > 0) {
    timestamp = process.readString();
  }
  return timestamp;
}

// Default state; waits on Yun to post state request
void state_00() {
  Serial.println("State: 00");
  wait_state();
  update_state();
}

// Example state
void state_99() {
  Serial.println("State: 99");
  while (true) {
    float rando = (random(100) / 100.0) + 1;
    Bridge.put("LED_99", String(rando));
    Bridge.put("DATA_TIMESTAMP", get_timestamp());
    Bridge.get("UPDATE", data, 10);
    if (String(data) == "True") {
      update_state();
      break;
    }
  }
}

