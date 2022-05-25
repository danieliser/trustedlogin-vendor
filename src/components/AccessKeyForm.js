import { useMemo, useState, useEffect } from "react";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import { useSettings } from "../hooks/useSettings";
import { HorizontalLogo } from "./TrustedLoginLogo";
import { SelectFieldArea, InputFieldArea } from "./teams/fields";
import TitleDescriptionLink from "./TitleDescriptionLink";
import { ToastError } from "./Errors";
function collectFormData(form) {
  let data = {};
  const formData = new FormData(form);
  for (let [key, value] of formData) {
    data[key] = value;
  }
  return data;
}

const getAccessKey = () => {
  if (window.tlVendor && window.tlVendor.accessKey.hasOwnProperty("ak")) {
    if (window.tlVendor.accessKey.ak.length > 0) {
      return window.tlVendor.accessKey.ak;
    }
  }
  return "";
};
const AccessKeyForm = ({ initialAccountId = null }) => {
  //State for access key in form or url
  //May be preset in window.tlVendor.accessKey.ak
  const [accessKey, setAccessKey] = useState(() => getAccessKey());
  //State for redicect data fetched via api
  //May be preset in window.tlVendor.redirectData
  const [redirectData, setRedirectData] = useState(() => {
    //Use data set server-side, if it is there
    if (window.tlVendor && window.tlVendor.hasOwnProperty("redirectData")) {
      let valid = true;
      //Make sure we got all the parts
      ["siteurl", "endpoint", "identifier"].forEach((key) => {
        if (!window.tlVendor.redirectData.hasOwnProperty(key)) {
          valid = false;
        }
      });
      if (valid) {
        return window.tlVendor.redirectData;
      }
    }
    return null;
  });

  const { settings, getTeam, teams } = useSettings();
  //State for account_id (not index) of the chosen team
  const [accountId, setAccountId] = useState(() => {
    //Would be index. Might be 0, which is valid
    //Or null if not.
    if (null != initialAccountId) {
      let team = getTeam(initialAccountId);
      if (team) {
        return team.account_id;
      }
    } else {
      //Only one team? Use that.
      if (1 == teams.length) {
        return teams[0].account_id;
      }
    }
    return "";
  });

  //Get all teams as options
  const teamsOption = useMemo(() => {
    if (!teams) {
      return [];
    }
    return teams.map((t) => {
      return {
        label: t.account_id,
        value: t.name ? t.name : t.account_id,
      };
    });
  }, [teams]);

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  const handler = (e) => {
    setErrorMessage("");
    let form = e.target;
    //No redirectData, trade access key for it.
    if (!redirectData) {
      setIsLoading(true);
      //Check if form input is valid
      if (!form.checkValidity()) {
        setIsLoading(false);
        return;
      }
      let data = collectFormData(form);

      e.preventDefault();
      //Try to get login redirect
      //https://developer.wordpress.org/block-editor/reference-guides/packages/packages-api-fetch/#usage
      apiFetch({
        path: "/trustedlogin/v1/access_key",
        method: "POST",
        data,
      })
        .catch((err) => {
          setIsLoading(false);
          if (
            err &&
            err.hasOwnProperty("message") &&
            "message" === typeof err.message
          ) {
            setErrorMessage(err.message);
          } else if (
            err &&
            err.hasOwnProperty("data") &&
            "string" === typeof err.data
          ) {
            setErrorMessage(err.data);
          } else {
            setErrorMessage(__("An error happended."));
          }
        })
        .then((res) => {
          if (res && res.hasOwnProperty("success") && res.success) {
            const { data } = res;
            setRedirectData(data);
            setIsLoading(false);
          }
        });
    } //Have redirectData, login with it.
    else {
      e.preventDefault();
      let data = collectFormData(form);
      fetch(redirectData.loginurl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
        credentials: "include",
      })
        .then((r) => {
          //Response good?
          if (r.ok()) {
            //Redirect to site,should be logged in.
            window.location = redirectData.siteurl;
            return;
          }
          setIsLoading(false);
          setErrorMessage(__("An error happended."));
        })
        .catch((err) => {
          setIsLoading(false);
          setErrorMessage(__("An error happended."));
        });
    }
  };

  //Once we have redirectData, submit form again
  useEffect(() => {
    if (redirectData) {
      document.getElementById("access-key-form").submit();
    }
  }, [redirectData]);

  return (
    <>
      <div className="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        <div className="mx-auto bg-white rounded-lg px-8 py-3 text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full sm:px-14 sm:py-8">
          <div className="w-full p-8 text-center">
            <HorizontalLogo />
          </div>
          <TitleDescriptionLink
            title={__("Log In Using Access Key", "trustedlogin-vendor")}
          />

          <>
            <form
              onSubmit={handler}
              id="access-key-form"
              method={"POST"}
              action={redirectData ? redirectData.siteurl : null}
              className="flex flex-col py-6 space-y-6 justify-center">
              {redirectData ? (
                <>
                  <div>{__("Redirecting", "trustedlogin")}</div>
                  <input type="hidden" name="action" value={"trustedlogin"} />
                  <input
                    type="hidden"
                    name="endpoint"
                    value={redirectData.endpoint}
                  />
                  <input
                    type="hidden"
                    name="identifier"
                    value={redirectData.identifier}
                  />
                </>
              ) : (
                <>
                  <input type="hidden" name="trustedlogin" value={1} />
                  <input
                    type="hidden"
                    name="action"
                    value={window.tlVendor.accessKey.action}
                  />
                  <input
                    type="hidden"
                    name="provider"
                    value={window.tlVendor.accessKey.provider}
                  />
                  <input
                    type="hidden"
                    name="_tl_ak_nonce"
                    value={window.tlVendor.accessKey._tl_ak_nonce}
                  />
                  {null !== initialAccountId ? (
                    <input
                      type="hidden"
                      name="ak_account_id"
                      value={accountId}
                    />
                  ) : (
                    <SelectFieldArea
                      name="ak_account_id"
                      id="ak_account_id"
                      label={__("Account ID", "trustedlogin-vendor")}
                      value={accountId}
                      onChange={(e) => setAccountId(e.target.value)}>
                      <>
                        <select
                          name="ak_account_id"
                          id="ak_account_id"
                          className="bg-white block w-full pl-3 pr-8 py-2.5 sm:text-sm border border-gray-300 rounded-lg focus:outline-none focus:border-sky-500 focus:ring-1 ring-offset-2 focus:ring-sky-500">
                          {teamsOption.map(({ label, value }) => (
                            <option key={value} value={value}>
                              {label}
                            </option>
                          ))}
                        </select>
                      </>
                    </SelectFieldArea>
                  )}

                  <div className="relative rounded-lg">
                    <InputFieldArea
                      name="ak"
                      id="ak"
                      label={__("Access Key", "trustedlogin-vendor")}>
                      <input
                        value={accessKey}
                        onChange={(e) => setAccessKey(e.target.value)}
                        type="text"
                        name="ak"
                        id="ak"
                        className="block w-full pl-4 pr-10 py-4 sm:text-md border border-gray-300 rounded-lg focus:outline-none focus:border-sky-500 focus:ring-1 ring-offset-2 focus:ring-sky-500"
                        placeholder={__(
                          "Paste key received from customer",
                          "trustedlogin-vendor"
                        )}
                      />
                    </InputFieldArea>
                  </div>

                  <>
                    {isLoading ? (
                      <div className="spinner is-active inline-flex justify-center p-4 border border-transparent text-md font-medium rounded-lg text-white bg-blue-tl"></div>
                    ) : (
                      <input
                        type="submit"
                        className="inline-flex justify-center p-4 border border-transparent text-md font-medium rounded-lg text-white bg-blue-tl hover:bg-indigo-700 focus:outline-none focus:ring-2 ring-offset-2 focus:ring-sky-500"
                        value={__("Log In", "trustedlogin-vendor")}
                      />
                    )}
                  </>
                </>
              )}
            </form>
            {errorMessage && <ToastError heading={errorMessage} />}
          </>
        </div>
      </div>
    </>
  );
};

export default AccessKeyForm;
