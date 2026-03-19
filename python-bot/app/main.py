from repository import Repository
from binance_rest import BinanceREST

repo = Repository()
binance = BinanceREST()


def main():
    print("===================================")
    print("STEP 4: BINANCE REST SETUP TEST")
    print("===================================")

    try:
        bots = repo.get_enabled_bots()
    except Exception as e:
        print(f"Database read failed: {e}")
        return

    if not bots:
        print("No enabled bots found.")
        return

    try:
        ping_result = binance.ping()
        print("Binance ping success:", ping_result)
    except Exception as e:
        print("Binance ping failed:", e)
        return

    for bot in bots:
        bot_id = bot["id"]
        symbol = bot["symbol"]
        leverage = int(bot["leverage"])

        margin_mode = str(bot["margin_mode"]).upper().strip()
        if margin_mode == "CROSS":
            margin_mode = "CROSSED"

        print("-----------------------------------")
        print(f"Bot ID: {bot_id}")
        print(f"Name: {bot['name']}")
        print(f"Symbol: {symbol}")
        print(f"Leverage: {leverage}")
        print(f"Margin Mode: {margin_mode}")

        try:
            rules = binance.get_symbol_rules(symbol)
            if not rules:
                msg = f"Symbol not found on Binance: {symbol}"
                print(msg)
                repo.insert_log(bot_id, "ERROR", msg)
                continue

            repo.insert_log(bot_id, "INFO", f"Symbol rules loaded for {symbol}")
            print(f"Symbol {symbol} exists.")

            print("=== DEBUG FOR", symbol, "===")

            try:
                open_orders = binance.get_open_orders(symbol)
                print("OPEN ORDERS:", open_orders)
            except Exception as e:
                print("OPEN ORDERS CHECK FAILED:", e)

            try:
                positions = binance.get_position_information(symbol)
                print("POSITIONS:", positions)
            except Exception as e:
                print("POSITIONS CHECK FAILED:", e)

            try:
                account = binance.get_futures_account()
                print("FUTURES ACCOUNT INFO LOADED")
                print(account)
            except Exception as e:
                print("FUTURES ACCOUNT CHECK FAILED:", e)

            try:
                margin_result = binance.change_margin_type(symbol, margin_mode)
                repo.insert_log(bot_id, "INFO", f"Margin mode set: {margin_result}")
                print("Margin mode result:", margin_result)
            except Exception as e:
                msg = str(e)

                if "No need to change margin type" in msg or "-4046" in msg:
                    repo.insert_log(bot_id, "INFO", f"Margin mode already set for {symbol}")
                    print("Margin mode already set.")
                else:
                    repo.insert_log(bot_id, "ERROR", f"Margin mode failed: {msg}")
                    print("Margin mode failed:", msg)

            try:
                leverage_result = binance.change_leverage(symbol, leverage)
                repo.insert_log(bot_id, "INFO", f"Leverage set: {leverage_result}")
                print("Leverage result:", leverage_result)
            except Exception as e:
                msg = str(e)
                repo.insert_log(bot_id, "ERROR", f"Leverage setup failed: {msg}")
                print("Leverage setup failed:", msg)
                continue

            repo.update_bot_state(
                bot_id=bot_id,
                status="IDLE",
                last_price=None,
                avg_entry_price=None,
                position_qty=None,
                safety_order_count=0,
                local_low=None,
                local_high=None
            )

            print("Bot setup complete.")

        except Exception as e:
            msg = f"Bot setup failed: {e}"
            print(msg)
            repo.insert_log(bot_id, "ERROR", msg)

    print("-----------------------------------")
    print("Step 4 complete.")


if __name__ == "__main__":
    main()