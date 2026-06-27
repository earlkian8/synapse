import * as ImagePicker from 'expo-image-picker';

/**
 * Capture a verification selfie for a DTR punch. Returns the local file URI, or
 * null when permission is denied or the user cancels (the selfie is optional).
 */
export async function captureSelfie(): Promise<string | null> {
  try {
    const { status } = await ImagePicker.requestCameraPermissionsAsync();

    if (status !== 'granted') {
      return null;
    }

    const result = await ImagePicker.launchCameraAsync({
      cameraType: ImagePicker.CameraType.front,
      quality: 0.5,
      allowsEditing: false,
    });

    if (result.canceled || !result.assets?.[0]) {
      return null;
    }

    return result.assets[0].uri;
  } catch {
    return null;
  }
}
