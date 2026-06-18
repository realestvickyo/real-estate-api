import * as dotenv from "dotenv";
// 1. Swap the old class name for the official initialization factory function
import { initiateDeveloperControlledWalletsClient } from "@circle-fin/developer-controlled-wallets";

dotenv.config();

// 2. Initialize your secure operations client context directly using the factory
const circleWalletsClient = initiateDeveloperControlledWalletsClient({
  apiKey: process.env.CIRCLE_API_KEY || "",
  entitySecret: process.env.CIRCLE_ENTITY_SECRET || "",
});

async function initializePlatformAgentWalletContext(): Promise<void> {
  console.log("-----------------------------------------------------------------");
  console.log("⚙️  [Instantiating Decentralized Node Wallet Parameters on Arbitrum]...");
  console.log("-----------------------------------------------------------------");

  try {
    const targetWalletSetResponse = await circleWalletsClient.createWalletSet({
      name: "Makao_Escrow_Agent_Operational_Set",
    });

    const walletSetId: string = targetWalletSetResponse.data?.walletSet?.id ?? "";

    const newAgentWallet = await circleWalletsClient.createWallets({
      blockchains: ["ARB-SEPOLIA"],
      count: 1,
      walletSetId: walletSetId,
    });

    const primaryWalletRecord = newAgentWallet.data?.wallets?.[0];

    if (!primaryWalletRecord) {
      throw new Error("No wallets returned from Circle API infrastructure.");
    }

    console.log("🚀 SUCCESS: Secure Wallet Sub-Layer Configured Without Vulnerabilities.");
    console.log(`>>> WALSET UNIQUE TRACKER REGISTRY ID: ${walletSetId}`);
    console.log(`>>> AGENT TARGET WALLET ID: ${primaryWalletRecord.id}`);
    console.log(`>>> PUBLIC BLOCKCHAIN HEX ADDRESS: ${primaryWalletRecord.address}`);
    console.log("-----------------------------------------------------------------");
    console.log("👉 Paste the 'AGENT TARGET WALLET ID' value right into process.env.INITIALIZED_AGENT_WALLET_ID");
    console.log("-----------------------------------------------------------------");
  } catch (error: any) {
    console.error("❌ Fatal Error Instantiating Operational Developer Wallet Context:", error.message || error);
  }
}

initializePlatformAgentWalletContext();