import * as dotenv from "dotenv";
import * as crypto from "crypto";
import { registerEntitySecretCiphertext } from "@circle-fin/developer-controlled-wallets";

dotenv.config();

async function runRegistration(): Promise<void> {
  const apiKey = process.env.CIRCLE_API_KEY;
  if (!apiKey) {
    console.error("Error: Please set your CIRCLE_API_KEY in your env configuration.");
    process.exit(1);
  }

  // Generate a cryptographically secure 32-byte hex secret locally
  const entitySecret: string = crypto.randomBytes(32).toString("hex");

  console.log("⚙️ Registering Entity Secret with Circle...");

  try {
    await registerEntitySecretCiphertext({
      apiKey: apiKey,
      entitySecret: entitySecret,
    });

    console.log("\n SUCCESS! Entity Secret registered successfully.");
    console.log("-------------------------------------------------------------");
    console.log(`YOUR ENTITY SECRET:\n${entitySecret}`);
    console.log("-------------------------------------------------------------");
    console.log("Copy the string above and paste it as your CIRCLE_ENTITY_SECRET in .env");
  } catch (error: any) {
    console.error("Registration failed:", error.message);
  }
}

runRegistration();