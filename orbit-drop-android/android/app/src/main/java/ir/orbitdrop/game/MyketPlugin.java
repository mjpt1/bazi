package ir.orbitdrop.game;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

// Opens Myket intents (https://myket.ir/docs/technical/intents/).
// Falls back to the Myket website when the Myket app is not installed.
@CapacitorPlugin(name = "Myket")
public class MyketPlugin extends Plugin {
    private static final String PKG = "ir.orbitdrop.game";

    // Stable per-device id (survives reinstall; scoped to this app's signing key on Android 8+)
    @PluginMethod
    public void deviceId(PluginCall call) {
        String id = android.provider.Settings.Secure.getString(
            getContext().getContentResolver(), android.provider.Settings.Secure.ANDROID_ID);
        JSObject ret = new JSObject();
        ret.put("id", id == null ? "" : "a-" + id);
        call.resolve(ret);
    }

    @PluginMethod
    public void open(PluginCall call) {
        String action = call.getString("action", "details");
        String uri;
        switch (action) {
            case "comment":   uri = "myket://comment?id=" + PKG; break;
            case "developer": uri = "myket://developer/" + PKG; break;
            case "update":    uri = "myket://check-update?id=" + PKG; break;
            default:          uri = "myket://details?id=" + PKG;
        }
        JSObject ret = new JSObject();
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(uri));
            getActivity().startActivity(i);
            ret.put("opened", "app");
        } catch (ActivityNotFoundException e) {
            Intent web = new Intent(Intent.ACTION_VIEW, Uri.parse("https://myket.ir/app/" + PKG));
            try { getActivity().startActivity(web); ret.put("opened", "web"); }
            catch (ActivityNotFoundException e2) { ret.put("opened", "none"); }
        }
        call.resolve(ret);
    }
}
